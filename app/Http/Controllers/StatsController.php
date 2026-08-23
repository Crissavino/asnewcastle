<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\StatsService;
use App\Support\CurrentClub;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    /** Las estadísticas propias: las ve cualquier jugador. */
    public function own(StatsService $stats): Response
    {
        return $this->render(app(CurrentClub::class)->member(), $stats);
    }

    /** Las de un compañero: solo el propio jugador o el manager (rol REAL). */
    public function member(Member $member, StatsService $stats): Response
    {
        $current = app(CurrentClub::class);

        abort_unless($member->club_id === $current->id(), 404);
        abort_unless(
            $member->id === $current->member()->id || $current->member()->isManager(),
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
