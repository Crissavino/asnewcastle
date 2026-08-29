<?php

namespace App\Http\Controllers;

use App\Models\Due;
use App\Models\Member;
use App\Services\StatsService;
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

        // Cuerpo técnico: aparte del plantel de jugadores.
        $staff = $current->club()->coaches()
            ->with('user:id,name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'role' => $m->role,
            ]);

        return Inertia::render('Perfil', [
            'me' => [
                'name' => $member->user->name,
                'first_name' => $member->user->firstName(),
                'last_name' => $member->user->lastName(),
                'role' => $member->role,
                'shirt_number' => $member->shirt_number,
                'position' => $member->position,
                'preferred_foot' => $member->preferred_foot,
                'availability' => $member->availability ?? [],
                // El técnico no tiene stats de jugador: muestra el récord del equipo.
                'record' => $member->isCoach() ? $this->coachRecord($member) : null,
            ],
            'season' => $member->isCoach() ? null : $this->season($member),
            'slots' => AltaController::SLOTS,
            'positions' => AltaController::POSITIONS,
            'feet' => AltaController::FEET,
            'max_number' => AltaController::MAX_NUMBER,
            'taken' => $this->takenNumbers($member),
            'roster' => $roster,
            'staff' => $staff,
        ]);
    }

    /** Récord del equipo desde que el técnico se sumó: dirigidos y G-E-P. */
    protected function coachRecord(Member $coach): array
    {
        $since = $coach->joined_at ?? $coach->created_at;

        $matches = \App\Models\Event::query()
            ->where('kind', 'match')
            ->whereNotNull('goals_for')
            ->whereNotNull('goals_against')
            ->where('starts_at', '>=', $since)
            ->get(['goals_for', 'goals_against']);

        return [
            'played' => $matches->count(),
            'won' => $matches->filter(fn ($m) => $m->goals_for > $m->goals_against)->count(),
            'drawn' => $matches->filter(fn ($m) => $m->goals_for === $m->goals_against)->count(),
            'lost' => $matches->filter(fn ($m) => $m->goals_for < $m->goals_against)->count(),
        ];
    }

    /** Corregir la ficha sin repetir el wizard: nombre, puesto, perfil, dorsal. */
    public function update(Request $request): RedirectResponse
    {
        $member = app(CurrentClub::class)->member();

        // El técnico solo edita su nombre.
        if ($member->isCoach()) {
            $validated = $request->validate([
                'first_name' => ['required', 'string', 'min:2', 'max:40'],
                'last_name' => ['required', 'string', 'min:2', 'max:40'],
            ]);

            $member->user->update([
                'name' => \App\Models\User::properCase($validated['first_name'].' '.$validated['last_name']),
            ]);

            return back();
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:40'],
            'last_name' => ['required', 'string', 'min:2', 'max:40'],
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
                $member->user->update([
                    'name' => \App\Models\User::properCase($validated['first_name'].' '.$validated['last_name']),
                ]);
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
        $stats = app(StatsService::class)->forMember($member);

        return [
            'matches' => $stats['matches_played'],
            'attendance_pct' => $stats['attendance_pct'],
            'mvps' => $stats['mvps'],
        ];
    }
}
