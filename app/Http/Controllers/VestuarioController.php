<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyVestuarioMessage;
use App\Models\Event;
use App\Models\Message;
use App\Services\Translation\LocaleGuesser;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class VestuarioController extends Controller
{
    public function show(): Response
    {
        $current = app(CurrentClub::class);
        $me = $current->member();

        // Entrar/pollear el vestuario = "leí hasta acá". Update directo para no
        // tocar updated_at en cada poll (cada 8s). Habilita la push de "1er sin leer".
        DB::table('members')->where('id', $me->id)->update(['vestuario_read_at' => now()]);

        $messages = Message::query()
            ->with('member.user:id,name')
            ->latest('id')
            ->limit(80)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'system' => $m->is_system ? json_decode($m->body, true) : null,
                'body' => $m->is_system ? null : $m->body,
                // Idioma detectado del mensaje: el front decide si ofrecer "traducir"
                'detected_locale' => $m->detected_locale,
                'attachment' => $m->attachment_path ? Storage::disk('public')->url($m->attachment_path) : null,
                'mine' => $m->member_id === $me->id,
                'author' => $m->member ? [
                    'name' => strtok($m->member->user->name ?? '', ' ') ?: $m->member->user->name,
                    'shirt_number' => $m->member->shirt_number,
                ] : null,
                'at' => $m->created_at->toIso8601String(),
            ]);

        return Inertia::render('Vestuario', [
            'messages' => $messages,
            'mvp' => fn () => $this->mvpPoll($me->id),
            'roster_count' => $current->club()->activeMembers()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'required_without:image', 'string', 'max:500'],
            'image' => ['nullable', 'required_without:body', 'image', 'max:8192'],
        ]);

        $path = null;

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store(
                'vestuario/'.app(CurrentClub::class)->id(),
                'public',
            );
        }

        $body = $validated['body'] ?? '';

        $message = Message::create([
            'member_id' => app(CurrentClub::class)->member()->id,
            'body' => $body,
            // Detección local (sin API) para saber si ofrecer traducir después
            'detected_locale' => trim($body) !== '' ? LocaleGuesser::guess($body) : null,
            'attachment_path' => $path,
            'is_system' => false,
        ]);

        // Push al plantel, solo al que estaba al día (1 por primer mensaje sin leer).
        NotifyVestuarioMessage::dispatch($message->id);

        return back();
    }

    /** La votación de figura del último partido terminado (ventana de 48hs). */
    protected function mvpPoll(int $myMemberId): ?array
    {
        $event = Event::query()
            ->where('kind', 'match')
            ->whereNotNull('mvp_opened_at')
            ->where('starts_at', '<', now())
            ->where('starts_at', '>', now()->subHours(50))
            ->with(['attendances.member.user:id,name', 'mvpVotes', 'playerRatings'])
            ->latest('starts_at')
            ->first();

        if (! $event || ! $event->mvpPollOpen()) {
            return null;
        }

        $votes = $event->mvpVotes->countBy('voted_member_id');
        $ratingsByPlayer = $event->playerRatings->groupBy('rated_member_id');
        $myRatings = $event->playerRatings->where('rater_member_id', $myMemberId);

        $presentIds = $event->presentMemberIds();

        $candidates = $event->attendances
            ->whereIn('member_id', $presentIds->all())
            ->map(function ($a) use ($votes, $ratingsByPlayer, $myRatings) {
                $ratings = $ratingsByPlayer->get($a->member_id, collect())->countBy('rating');

                return [
                    'id' => $a->member_id,
                    'name' => $a->member->user->name,
                    'shirt_number' => $a->member->shirt_number,
                    'votes' => $votes->get($a->member_id, 0),
                    // Totales anónimos por nivel: [le costó, cumplió, crack]
                    'ratings' => [$ratings->get(1, 0), $ratings->get(2, 0), $ratings->get(3, 0)],
                    'my_rating' => $myRatings->firstWhere('rated_member_id', $a->member_id)?->rating,
                ];
            })
            ->sortByDesc('votes')
            ->values();

        if ($candidates->count() < 2) {
            return null;
        }

        return [
            'event_id' => $event->id,
            'opponent' => $event->opponent,
            'total_votes' => $event->mvpVotes->count(),
            'my_vote' => $event->mvpVotes->firstWhere('voter_member_id', $myMemberId)?->voted_member_id,
            'candidates' => $candidates,
        ];
    }
}
