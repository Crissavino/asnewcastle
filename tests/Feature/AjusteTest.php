<?php

use App\Models\Member;
use App\Models\Movement;
use App\Services\Ledger;

function ajustar($test, Member $m, array $extra = [])
{
    return $test->actingAs($m->user)->post('/movimientos/ajuste', [
        'pot' => 'cuenta', 'target_cents' => 18000, 'note' => 'el extracto dice 180', ...$extra,
    ]);
}

function ingresar($test, Member $m, array $extra = [])
{
    return $test->actingAs($m->user)->post('/movimientos/ingreso', [
        'pot' => 'cuenta', 'amount_cents' => 60000,
        'occurred_on' => now()->toDateString(), 'note' => 'patrimonio inicial', ...$extra,
    ]);
}

it('el ajuste se carga como saldo objetivo y guarda la diferencia', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 22500]);

    ajustar($this, $m)->assertRedirect()->assertSessionMissing('aviso_saldo_negativo');

    $ajuste = Movement::query()->where('kind', 'ajuste')->sole();
    expect(app(Ledger::class)->balance('cuenta'))->toBe(18000)
        ->and($ajuste->amount_cents)->toBe(-4500)
        ->and($ajuste->pot)->toBe('cuenta')
        ->and($ajuste->note)->toBe('el extracto dice 180')
        ->and($ajuste->created_by_member_id)->toBe($m->id);
});

it('el ajuste sube el saldo si el objetivo es mayor', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    Movement::factory()->create(['pot' => 'cash', 'amount_cents' => 1000]);

    ajustar($this, $m, ['pot' => 'cash', 'target_cents' => 5000])->assertRedirect();

    expect(Movement::query()->where('kind', 'ajuste')->sole()->amount_cents)->toBe(4000)
        ->and(app(Ledger::class)->balance('cash'))->toBe(5000);
});

it('un ajuste de diferencia cero no crea fila', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 18000]);

    ajustar($this, $m)->assertRedirect()->assertSessionHasNoErrors();

    expect(Movement::query()->where('kind', 'ajuste')->count())->toBe(0);
});

it('el ajuste que deja el bolsillo en negativo pasa, pero avisa', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 5000]);

    ajustar($this, $m, ['target_cents' => -2000])->assertRedirect()->assertSessionHas('aviso_saldo_negativo', true);

    expect(app(Ledger::class)->balance('cuenta'))->toBe(-2000);
});

it('el ajuste exige nota', function () {
    $m = Member::factory()->manager()->create();
    ajustar($this, $m, ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($m->user)->post('/movimientos/ajuste', ['pot' => 'cuenta', 'target_cents' => 18000])
        ->assertSessionHasErrors('note');
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('el ajuste valida bolsillo y objetivo entero', function () {
    $m = Member::factory()->manager()->create();
    ajustar($this, $m, ['pot' => 'caja'])->assertSessionHasErrors('pot');
    ajustar($this, $m, ['target_cents' => 12.5])->assertSessionHasErrors('target_cents');
    ajustar($this, $m, ['target_cents' => 'x'])->assertSessionHasErrors('target_cents');
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('un jugador no puede ajustar', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    ajustar($this, $player)->assertForbidden();
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('el ajuste usa el saldo del club propio, no el de otro', function () {
    $m = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    Movement::factory()->create(['club_id' => $ajeno->club_id, 'pot' => 'cuenta', 'amount_cents' => 99999]);

    ajustar($this, $m)->assertRedirect();

    expect(Movement::withoutGlobalScopes()->where('club_id', $m->club_id)->sole()->amount_cents)->toBe(18000)
        ->and(Movement::withoutGlobalScopes()->where('club_id', $ajeno->club_id)->count())->toBe(1);
});

it('un ingreso que no es cuota sube el bolsillo', function () {
    $m = Member::factory()->manager()->create();
    ingresar($this, $m)->assertRedirect();

    fijarClub($m);
    $fila = Movement::query()->sole();
    expect(app(Ledger::class)->balance('cuenta'))->toBe(60000)
        ->and($fila->kind)->toBe('ingreso')
        ->and($fila->note)->toBe('patrimonio inicial')
        ->and($fila->created_by_member_id)->toBe($m->id);
});

it('el ingreso exige nota y monto positivo entero', function () {
    $m = Member::factory()->manager()->create();
    ingresar($this, $m, ['note' => ''])->assertSessionHasErrors('note');
    ingresar($this, $m, ['amount_cents' => 0])->assertSessionHasErrors('amount_cents');
    ingresar($this, $m, ['amount_cents' => -5])->assertSessionHasErrors('amount_cents');
    ingresar($this, $m, ['amount_cents' => 1.5])->assertSessionHasErrors('amount_cents');
    ingresar($this, $m, ['pot' => 'caja'])->assertSessionHasErrors('pot');
    ingresar($this, $m, ['occurred_on' => 'ayer'])->assertSessionHasErrors('occurred_on');
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('un jugador no puede cargar un ingreso', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    ingresar($this, $player)->assertForbidden();
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('el ingreso queda en el club del manager y no toca al otro', function () {
    $m = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    ingresar($this, $m);

    expect(Movement::withoutGlobalScopes()->where('club_id', $m->club_id)->count())->toBe(1)
        ->and(Movement::withoutGlobalScopes()->where('club_id', $ajeno->club_id)->count())->toBe(0);
});
