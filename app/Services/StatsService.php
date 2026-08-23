<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Member;
use App\Models\MvpVote;
use App\Models\PlayerRating;

/**
 * Estadísticas personales. Todo sale de lo que ya registra la app —
 * asistencias, presentes confirmados, votos de figura y calificaciones
 * del vestuario — calculado al leer: a esta escala no hay nada que cachear.
 */
class StatsService
{
    public function forMember(Member $member): array
    {
        $events = Event::query()
            ->whereNull('cancelled_at')
            ->where('starts_at', '<', now())
            ->when($member->joined_at, fn ($q) => $q->where('starts_at', '>=', $member->joined_at))
            ->with('attendances')
            ->orderByDesc('starts_at')
            ->get();

        $matches = $events->where('kind', 'match')->values();
        $trainings = $events->where('kind', 'training')->values();

        $played = fn (Event $e) => $e->wasPresent($member->id);
        $matchesPlayed = $matches->filter($played)->count();
        $trainingsAttended = $trainings->filter($played)->count();
        $presentTotal = $matchesPlayed + $trainingsAttended;

        // Racha: partidos seguidos jugados, del más reciente hacia atrás
        $streak = 0;
        foreach ($matches as $m) {
            if (! $played($m)) {
                break;
            }
            $streak++;
        }

        // Faltazos: dijo "Voy" y el manager lo marcó ausente
        $absences = $events
            ->filter(fn (Event $e) => $e->attendance_confirmed_at && $e->attendances->contains(
                fn ($a) => $a->member_id === $member->id && $a->status === 'in' && $a->attended === false
            ))
            ->count();

        // Figuras y votos: sobre todos los partidos del club (un voto solo
        // puede existir si estuvo, así que no hace falta filtrar por fecha)
        $mvps = Event::query()
            ->where('kind', 'match')
            ->whereNotNull('mvp_closed_at')
            ->whereHas('mvpVotes')
            ->with('mvpVotes')
            ->get()
            ->filter(function (Event $event) use ($member) {
                $counts = $event->mvpVotes->countBy('voted_member_id');

                return $counts->get($member->id, 0) === $counts->max();
            })
            ->count();

        $mvpVotes = MvpVote::query()->where('voted_member_id', $member->id)->count();

        $ratings = PlayerRating::query()->where('rated_member_id', $member->id)->get();
        $byLevel = $ratings->countBy('rating');
        $distribution = [$byLevel->get(1, 0), $byLevel->get(2, 0), $byLevel->get(3, 0)];

        $votesByEvent = MvpVote::query()
            ->whereIn('event_id', $matches->pluck('id'))
            ->where('voted_member_id', $member->id)
            ->get()
            ->countBy('event_id');

        $allVotesByEvent = MvpVote::query()
            ->whereIn('event_id', $matches->pluck('id'))
            ->get()
            ->groupBy('event_id');

        $ratingsByEvent = $ratings->groupBy('event_id');

        $timeline = $matches->map(function (Event $e) use ($member, $played, $votesByEvent, $allVotesByEvent, $ratingsByEvent) {
            $counts = ($allVotesByEvent->get($e->id) ?? collect())->countBy('voted_member_id');
            $eventRatings = ($ratingsByEvent->get($e->id) ?? collect())->countBy('rating');

            return [
                'id' => $e->id,
                'opponent' => $e->opponent,
                'is_home' => $e->is_home,
                'starts_at' => $e->starts_at->toIso8601String(),
                'result' => $e->hasResult() ? ['gf' => $e->goals_for, 'ga' => $e->goals_against] : null,
                'played' => $played($e),
                'votes' => $votesByEvent->get($e->id, 0),
                'ratings' => [$eventRatings->get(1, 0), $eventRatings->get(2, 0), $eventRatings->get(3, 0)],
                'mvp' => $e->mvp_closed_at !== null
                    && $counts->isNotEmpty()
                    && $counts->get($member->id, 0) === $counts->max(),
            ];
        })->values()->all();

        return [
            'member' => [
                'id' => $member->id,
                'name' => $member->user->name,
                'shirt_number' => $member->shirt_number,
                'position' => $member->position,
            ],
            'matches_played' => $matchesPlayed,
            'matches_total' => $matches->count(),
            'match_pct' => $matches->count() > 0 ? (int) round($matchesPlayed / $matches->count() * 100) : null,
            'trainings_attended' => $trainingsAttended,
            'trainings_total' => $trainings->count(),
            'training_pct' => $trainings->count() > 0 ? (int) round($trainingsAttended / $trainings->count() * 100) : null,
            'attendance_pct' => $events->count() > 0 ? (int) round($presentTotal / $events->count() * 100) : null,
            'streak' => $streak,
            'absences' => $absences,
            'mvps' => $mvps,
            'mvp_votes' => $mvpVotes,
            'ratings' => $distribution,
            'rating_avg' => $ratings->isNotEmpty() ? round($ratings->avg('rating'), 1) : null,
            'timeline' => $timeline,
        ];
    }
}
