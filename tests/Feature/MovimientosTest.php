<?php

use App\Models\Member;
use App\Models\Movement;
use App\Services\Ledger;

it('un movimiento de otro club no se ve', function () {
    $mio = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    Movement::factory()->for($ajeno->club)->create(['amount_cents' => 50000]);

    fijarClub($mio);
    expect(Movement::query()->count())->toBe(0);
});

it('guarda signo, bolsillo y tipo', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    $mov = Movement::create([
        'pot' => 'cuenta', 'kind' => 'saldo_inicial',
        'amount_cents' => 22500, 'occurred_on' => now()->toDateString(),
    ]);
    expect($mov->fresh()->amount_cents)->toBe(22500)
        ->and($mov->club_id)->toBe($m->club_id);
});

it('el saldo de cada bolsillo es la suma de sus movimientos', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 30000]);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => -7500]);
    Movement::factory()->create(['pot' => 'cash', 'amount_cents' => 10000]);

    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 22500, 'cash' => 10000]);
});

it('un bolsillo sin movimientos vale cero, no null', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    expect(app(Ledger::class)->balance('cash'))->toBe(0)
        ->and(app(Ledger::class)->balances())->toBe(['cuenta' => 0, 'cash' => 0]);
});

it('el historial viene del más nuevo al más viejo y respeta el límite', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    $viejo = Movement::factory()->create(['occurred_on' => '2026-10-01']);
    $a = Movement::factory()->create(['occurred_on' => '2026-11-01']);
    $b = Movement::factory()->create(['occurred_on' => '2026-11-01']);

    $ids = app(Ledger::class)->history()->pluck('id')->all();
    expect($ids)->toBe([$b->id, $a->id, $viejo->id])
        ->and(app(Ledger::class)->history(2))->toHaveCount(2);
});
