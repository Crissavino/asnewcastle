<?php

use App\Models\Expense;
use App\Models\Member;
use App\Models\Movement;
use App\Services\Ledger;

function transferir($test, Member $m, array $extra = [])
{
    return $test->actingAs($m->user)->post('/movimientos/transferencia', [
        'from' => 'cuenta', 'amount_cents' => 30000,
        'occurred_on' => now()->toDateString(), ...$extra,
    ]);
}

it('mueve plata de un bolsillo al otro sin cambiar el total', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 50000]);

    transferir($this, $m)->assertRedirect()->assertSessionMissing('aviso_saldo_negativo');

    $s = app(Ledger::class)->balances();
    expect($s)->toBe(['cuenta' => 20000, 'cash' => 30000])
        ->and(array_sum($s))->toBe(50000);
});

it('escribe dos filas opuestas atadas por el mismo group_uuid', function () {
    $m = Member::factory()->manager()->create();
    transferir($this, $m, ['from' => 'cash', 'note' => 'Para la cancha'])->assertRedirect();

    fijarClub($m);
    $filas = Movement::query()->orderBy('id')->get();
    expect($filas)->toHaveCount(2)
        ->and($filas[0]->group_uuid)->not->toBeNull()
        ->and($filas[0]->group_uuid)->toBe($filas[1]->group_uuid)
        ->and($filas->pluck('kind')->unique()->all())->toBe(['transferencia'])
        ->and($filas[0]->pot)->toBe('cash')->and($filas[0]->amount_cents)->toBe(-30000)
        ->and($filas[1]->pot)->toBe('cuenta')->and($filas[1]->amount_cents)->toBe(30000)
        ->and($filas->sum('amount_cents'))->toBe(0)
        ->and($filas[0]->note)->toBe('Para la cancha')
        ->and($filas[0]->created_by_member_id)->toBe($m->id);
});

it('la transferencia no crea un gasto', function () {
    $m = Member::factory()->manager()->create();
    transferir($this, $m);
    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});

it('un jugador no puede transferir', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    transferir($this, $player)->assertForbidden();
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('valida bolsillo de origen, monto y fecha', function () {
    $m = Member::factory()->manager()->create();
    transferir($this, $m, ['from' => 'caja'])->assertSessionHasErrors('from');
    transferir($this, $m, ['amount_cents' => 0])->assertSessionHasErrors('amount_cents');
    transferir($this, $m, ['amount_cents' => 12.5])->assertSessionHasErrors('amount_cents');
    transferir($this, $m, ['occurred_on' => 'ayer'])->assertSessionHasErrors('occurred_on');
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('mover más de lo que hay pasa igual, pero avisa', function () {
    $m = Member::factory()->manager()->create();
    transferir($this, $m)->assertRedirect()->assertSessionHas('aviso_saldo_negativo', true);

    fijarClub($m);
    expect(app(Ledger::class)->balances())->toBe(['cuenta' => -30000, 'cash' => 30000]);
});

it('la transferencia queda en el club del manager y no toca al otro', function () {
    $m = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    transferir($this, $m);

    expect(Movement::withoutGlobalScopes()->where('club_id', $m->club_id)->count())->toBe(2)
        ->and(Movement::withoutGlobalScopes()->where('club_id', $ajeno->club_id)->count())->toBe(0);
});
