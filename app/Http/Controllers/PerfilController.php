<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Due;
use App\Models\Event;
use App\Models\Member;
use App\Support\CurrentClub;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PerfilController extends Controller
{
    public function show(): Response
    {
        $current = app(CurrentClub::class);
        $member = $current->member();

        // El plantel: dorsal, nombre, puesto y estado de cuota. Nunca teléfonos.
        $dues = Due::query()
            ->whereDate('period', now()->startOfMonth())
            ->get()
            ->keyBy('member_id');

        $roster = $current->club()->activeMembers()
            ->with('user:id,name')
            ->orderBy('shirt_number')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'shirt_number' => $m->shirt_number,
                'position' => $m->position,
                'role' => $m->role,
                // Hacia afuera solo "al día" o "debe": becados/condonados van al día
                'due_status' => $dues->get($m->id)?->status === 'pending' ? 'pending' : 'paid',
            ]);

        return Inertia::render('Perfil', [
            'me' => [
                'name' => $member->user->name,
                'shirt_number' => $member->shirt_number,
                'position' => $member->position,
                'preferred_foot' => $member->preferred_foot,
                'availability' => $member->availability ?? [],
            ],
            'season' => $this->season($member),
            'slots' => AltaController::SLOTS,
            'positions' => AltaController::POSITIONS,
            'feet' => AltaController::FEET,
            'max_number' => AltaController::MAX_NUMBER,
            'taken' => $this->takenNumbers($member),
            'roster' => $roster,
        ]);
    }

    /** Corregir la ficha sin repetir el wizard: nombre, puesto, perfil, dorsal. */
    public function update(Request $request): RedirectResponse
    {
        $member = app(CurrentClub::class)->member();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'position' => ['required', Rule::in(AltaController::POSITIONS)],
            'preferred_foot' => ['required', Rule::in(AltaController::FEET)],
            'shirt_number' => [
                'required', 'integer', 'min:1', 'max:'.AltaController::MAX_NUMBER,
                Rule::notIn($this->takenNumbers($member)),
            ],
        ], [
            'shirt_number.not_in' => __('alta.number_taken'),
        ]);

        try {
            DB::transaction(function () use ($validated, $member) {
                $member->user->update(['name' => $validated['name']]);
                $member->update([
                    'position' => $validated['position'],
                    'preferred_foot' => $validated['preferred_foot'],
                    'shirt_number' => $validated['shirt_number'],
                ]);
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[0] === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
                throw ValidationException::withMessages(['shirt_number' => __('alta.number_taken')]);
            }

            throw $e;
        }

        return back();
    }

    /** La disponibilidad se edita desde el perfil, sin repetir el wizard. */
    public function updateAvailability(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'availability' => ['required', 'array', 'min:1'],
            'availability.*' => [Rule::in(AltaController::SLOTS)],
        ]);

        app(CurrentClub::class)->member()->update([
            'availability' => array_values(array_unique($validated['availability'])),
        ]);

        return back();
    }

    /** Nombrar o quitar administradores. El admin también juega: el rol no toca la ficha. */
    public function setRole(Request $request, Member $member): RedirectResponse
    {
        $current = app(CurrentClub::class);

        abort_unless($current->member()->isManager(), 403);
        abort_unless($member->club_id === $current->id(), 404);
        // El propio rol no se toca: así el club nunca se queda sin admin
        abort_if($member->id === $current->member()->id, 403);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['player', 'manager'])],
        ]);

        $member->update(['role' => $validated['role']]);

        return back();
    }

    /** Baja del plantel: deja de recibir convocatorias y de generar cuotas. */
    public function removeMember(Member $member): RedirectResponse
    {
        $current = app(CurrentClub::class);

        abort_unless($current->member()->isManager(), 403);
        abort_unless($member->club_id === $current->id(), 404);
        abort_if($member->id === $current->member()->id, 403); // a vos mismo no
        abort_if($member->isManager(), 403); // a otro delegado tampoco

        $member->update(['left_at' => now()]);

        return back();
    }

    /** Dorsales ocupados en el club, sin contar el propio. */
    protected function takenNumbers(Member $member): array
    {
        return app(CurrentClub::class)->club()->activeMembers()
            ->whereNotNull('shirt_number')
            ->whereKeyNot($member->id)
            ->pluck('shirt_number')
            ->all();
    }

    /** Números de la temporada: sale todo de asistencias y votos, sin planilla. */
    protected function season(Member $member): array
    {
        $pastEvents = Event::query()
            ->whereNull('cancelled_at')
            ->where('starts_at', '<', now())
            ->when($member->joined_at, fn ($q) => $q->where('starts_at', '>=', $member->joined_at));

        $totalPast = (clone $pastEvents)->count();

        $attended = Attendance::query()
            ->where('member_id', $member->id)
            ->where('status', 'in')
            ->whereIn('event_id', (clone $pastEvents)->pluck('id'));

        $matchesPlayed = (clone $attended)
            ->whereIn('event_id', (clone $pastEvents)->where('kind', 'match')->pluck('id'))
            ->count();

        // Figuras: partidos con votación cerrada donde este member quedó arriba
        $mvps = Event::query()
            ->where('kind', 'match')
            ->whereNotNull('mvp_closed_at')
            ->with('mvpVotes')
            ->get()
            ->filter(function (Event $event) use ($member) {
                $counts = $event->mvpVotes->countBy('voted_member_id');

                return $counts->isNotEmpty() && $counts->get($member->id, 0) === $counts->max();
            })
            ->count();

        return [
            'matches' => $matchesPlayed,
            'attendance_pct' => $totalPast > 0 ? (int) round($attended->count() / $totalPast * 100) : null,
            'mvps' => $mvps,
        ];
    }
}
