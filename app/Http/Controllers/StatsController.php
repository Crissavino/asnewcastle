<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\StatsService;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    /** Las estadísticas propias: las ve cualquier jugador. El técnico no juega. */
    public function own(StatsService $stats): Response|RedirectResponse
    {
        $member = app(CurrentClub::class)->member();

        if ($member->isCoach()) {
            return redirect()->route('perfil');
        }

        return $this->render($member, $stats);
    }

    /** Las de un compañero: el propio jugador, el manager o el técnico (ve a todos). */
    public function member(Member $member, StatsService $stats): Response|RedirectResponse
    {
        $current = app(CurrentClub::class);

        abort_unless($member->club_id === $current->id(), 404);

        // El técnico no tiene stats de jugador: su ficha muestra el récord.
        if ($member->isCoach()) {
            return redirect()->route('perfil');
        }

        abort_unless(
            $member->id === $current->member()->id
            || $current->member()->isManager()
            || $current->member()->isCoach(),
            403
        );

        return $this->render($member, $stats);
    }

    protected function render(Member $member, StatsService $stats): Response
    {
        return Inertia::render('Estadisticas', [
            'stats' => $stats->forMember($member),
        ]);
    }
}
