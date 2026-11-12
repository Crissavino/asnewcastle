<?php

use App\Models\Expense;
use App\Models\Member;
use App\Models\Movement;
use App\Services\Ledger;

function cargarGasto($test, Member $m, array $extra = [])
{
    return $test->actingAs($m->user)->post('/gastos', [
        'category' => 'pitch', 'amount_cents' => 15000,
        'spent_on' => now()->toDateString(), ...$extra,
    ]);
}

it('cargar un gasto baja el bolsillo que se eligió', function () {
    $m = Member::factory()->manager()->create();
    cargarGasto($this, $m, ['pot' => 'cash'])->assertRedirect();

    fijarClub($m);
    $mov = Movement::query()->sole();
    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 0, 'cash' => -15000])
        ->and($mov->kind)->toBe('gasto')
        ->and($mov->expense_id)->toBe(Expense::query()->sole()->id)
        ->and($mov->created_by_member_id)->toBe($m->id);
});

it('el gasto exige bolsillo válido', function () {
    $m = Member::factory()->manager()->create();
    cargarGasto($this, $m)->assertSessionHasErrors('pot');
    cargarGasto($this, $m, ['pot' => 'caja'])->assertSessionHasErrors('pot');
    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});

it('borrar un gasto contra-asienta en vez de borrar el movimiento', function () {
    $m = Member::factory()->manager()->create();
    cargarGasto($this, $m, ['pot' => 'cash']);
    $expense = Expense::query()->sole();
    $this->actingAs($m->user)->delete("/gastos/{$expense->id}")->assertRedirect();

    fijarClub($m);
    $original = Movement::query()->orderBy('id')->first();
    $inverso = Movement::query()->orderByDesc('id')->first();
    expect(app(Ledger::class)->balance('cash'))->toBe(0)
        ->and(Movement::query()->count())->toBe(2)
        ->and($inverso->amount_cents)->toBe(15000)
        ->and($inverso->pot)->toBe('cash')
        ->and($inverso->reverses_movement_id)->toBe($original->id);
});

it('un gasto mayor al efectivo pasa igual, pero avisa', function () {
    $m = Member::factory()->manager()->create();
    cargarGasto($this, $m, ['pot' => 'cash'])
        ->assertRedirect()->assertSessionHas('aviso_saldo_negativo', true);

    fijarClub($m);
    expect(app(Ledger::class)->balance('cash'))->toBe(-15000);
});

it('un gasto que no deja el bolsillo en negativo no avisa', function () {
    $m = Member::factory()->manager()->create();
    fijarClub($m);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 20000]);
    cargarGasto($this, $m, ['pot' => 'cuenta'])
        ->assertRedirect()->assertSessionMissing('aviso_saldo_negativo');
});

it('borrar dos veces no contra-asienta dos veces', function () {
    $m = Member::factory()->manager()->create();
    cargarGasto($this, $m, ['pot' => 'cash']);
    $expense = Expense::query()->sole();
    $this->actingAs($m->user)->delete("/gastos/{$expense->id}");
    $this->actingAs($m->user)->delete("/gastos/{$expense->id}")->assertNotFound();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cash'))->toBe(0)
        ->and(Movement::query()->count())->toBe(2);
});

it('borrar un gasto viejo sin bolsillo no escribe movimientos', function () {
    $m = Member::factory()->manager()->create();
    $expense = Expense::create([
        'club_id' => $m->club_id, 'member_id' => $m->id, 'category' => 'other',
        'amount_cents' => 5000, 'spent_on' => now(),
    ]);
    $this->actingAs($m->user)->delete("/gastos/{$expense->id}")->assertRedirect();

    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('un manager no puede borrar el gasto de otro club ni mover su libro', function () {
    $m = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    cargarGasto($this, $ajeno, ['pot' => 'cash']);
    $gastoAjeno = Expense::withoutGlobalScopes()->sole();

    $this->actingAs($m->user)->delete("/gastos/{$gastoAjeno->id}")->assertNotFound();
    expect(Movement::withoutGlobalScopes()->where('club_id', $ajeno->club_id)->count())->toBe(1)
        ->and(Movement::withoutGlobalScopes()->where('club_id', $m->club_id)->count())->toBe(0);
});

it('si el gasto ya fue borrado por otra request, el borrado aborta 404 sin contra-asentar', function () {
    $m = Member::factory()->manager()->create();
    cargarGasto($this, $m, ['pot' => 'cash']);
    $expense = Expense::query()->sole();

    // Simula al DELETE concurrente ya resuelto: el binding de la segunda request
    // vio el gasto, pero cuando toma el lock otro ya lo borró.
    $this->actingAs($m->user);
    $viejo = clone $expense;
    $expense->delete();

    $controller = app(\App\Http\Controllers\ExpenseController::class);
    fijarClub($m);
    expect(fn () => $controller->destroy($viejo))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(Movement::query()->count())->toBe(1);
});

it('un gasto con bolsillo pero sin movimiento no se borra en silencio', function () {
    $m = Member::factory()->manager()->create();
    $expense = Expense::create([
        'club_id' => $m->club_id, 'member_id' => $m->id, 'category' => 'other',
        'amount_cents' => 5000, 'spent_on' => now(), 'pot' => 'cash',
    ]);
    $this->actingAs($m->user)->delete("/gastos/{$expense->id}")->assertNotFound();

    expect(Expense::withoutGlobalScopes()->count())->toBe(1);
});
