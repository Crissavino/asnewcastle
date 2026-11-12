<?php

use App\Models\Member;
use App\Models\Movement;
use App\Support\CurrentClub;

/**
 * Fuera de una request nadie corre el middleware SetActiveClub, así que los
 * tests fijan el club activo a mano para que el global scope filtre.
 */
function activarClub(Member $member): void
{
    app(CurrentClub::class)->set($member->club, $member);
}

it('un movimiento de otro club no se ve', function () {
    $mio = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    Movement::factory()->for($ajeno->club)->create(['amount_cents' => 50000]);

    activarClub($mio);
    expect(Movement::query()->count())->toBe(0);
});

it('guarda signo, bolsillo y tipo', function () {
    $m = Member::factory()->manager()->create();
    activarClub($m);
    $mov = Movement::create([
        'pot' => 'cuenta', 'kind' => 'saldo_inicial',
        'amount_cents' => 22500, 'occurred_on' => now()->toDateString(),
    ]);
    expect($mov->fresh()->amount_cents)->toBe(22500)
        ->and($mov->club_id)->toBe($m->club_id);
});
