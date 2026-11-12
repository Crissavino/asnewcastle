<?php

use App\Models\Due;
use App\Models\Member;
use App\Models\Movement;
use App\Services\Ledger;
use Illuminate\Support\Facades\Artisan;

function marcarCuota($test, Member $m, Due $due, array $data)
{
    return $test->actingAs($m->user)->post("/cuota/{$due->id}/estado", $data);
}

it('marcar una cuota pagada en cash sube el efectivo', function () {
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create(['amount_cents' => 30000]);
    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'cash'])->assertRedirect();

    fijarClub($m);
    $mov = Movement::query()->sole();
    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 0, 'cash' => 30000])
        ->and($mov->kind)->toBe('cuota')
        ->and($mov->due_id)->toBe($due->id)
        ->and($mov->created_by_member_id)->toBe($m->id);
});

it('marcar pagada con bolsillo cuenta sube la cuenta', function () {
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create(['amount_cents' => 30000]);
    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'cuenta'])->assertRedirect();

    fijarClub($m);
    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 30000, 'cash' => 0]);
});

it('exige bolsillo válido solo al marcar pagada', function () {
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create();
    marcarCuota($this, $m, $due, ['status' => 'paid'])->assertSessionHasErrors('pot');
    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'caja'])->assertSessionHasErrors('pot');
    expect($due->fresh()->status)->toBe('pending');

    marcarCuota($this, $m, $due, ['status' => 'waived'])->assertRedirect();
    expect($due->fresh()->status)->toBe('waived')
        ->and(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('paid -> pending -> paid no deja dos movimientos sumando', function () {
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create(['amount_cents' => 30000]);
    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'cash']);
    marcarCuota($this, $m, $due, ['status' => 'pending']);

    fijarClub($m);
    expect(app(Ledger::class)->balance('cash'))->toBe(0);

    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'cash']);
    expect(app(Ledger::class)->balance('cash'))->toBe(30000)
        ->and(Movement::query()->count())->toBe(3);
});

it('paid -> pending -> paid puede cambiar de bolsillo y el viejo vuelve a cero', function () {
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create(['amount_cents' => 30000]);
    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'cash']);
    marcarCuota($this, $m, $due, ['status' => 'pending']);
    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'cuenta']);

    fijarClub($m);
    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 30000, 'cash' => 0]);
});

it('paid -> waived contra-asienta y paid -> paid no duplica', function () {
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create(['amount_cents' => 30000]);
    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'cash']);
    marcarCuota($this, $m, $due, ['status' => 'paid', 'pot' => 'cash']);

    fijarClub($m);
    expect(app(Ledger::class)->balance('cash'))->toBe(30000);

    marcarCuota($this, $m, $due, ['status' => 'waived']);
    expect(app(Ledger::class)->balance('cash'))->toBe(0)
        ->and(Movement::query()->whereNotNull('reverses_movement_id')->count())->toBe(1);
});

it('un jugador no marca cuotas ni mueve el libro', function () {
    $m = Member::factory()->manager()->create();
    $player = Member::factory()->for($m->club)->create();
    $due = Due::factory()->forMember($player)->create();
    marcarCuota($this, $player, $due, ['status' => 'paid', 'pot' => 'cash'])->assertForbidden();
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('el delegado de otro club no toca la cuota ni el libro de este', function () {
    $mio = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($mio)->create();

    marcarCuota($this, $ajeno, $due, ['status' => 'paid', 'pot' => 'cash'])->assertNotFound();
    expect($due->fresh()->status)->toBe('pending')
        ->and(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('app:cuota-efectivo asienta el movimiento en cash por defecto y acepta --pot', function () {
    $m = Member::factory()->create();
    $due = Due::factory()->forMember($m)->create();

    Artisan::call('app:cuota-efectivo', ['nombre' => $m->user->name, 'lei' => 150, 'modo' => 'go']);

    $mov = Movement::withoutGlobalScopes()->sole();
    expect($mov->pot)->toBe('cash')
        ->and($mov->kind)->toBe('cuota')
        ->and($mov->amount_cents)->toBe(15000)
        ->and($mov->due_id)->toBe($due->id);

    // Repetir el comando sobre la misma cuota no duplica el asiento
    Artisan::call('app:cuota-efectivo', ['nombre' => $m->user->name, 'lei' => 150, 'modo' => 'go']);
    expect(Movement::withoutGlobalScopes()->sole()->id)->toBe($mov->id);
});

it('app:cuota-efectivo con --pot=cuenta asienta en la cuenta', function () {
    $m = Member::factory()->create();
    Artisan::call('app:cuota-efectivo', ['nombre' => $m->user->name, 'lei' => 100, 'modo' => 'go', '--pot' => 'cuenta']);
    expect(Movement::withoutGlobalScopes()->sole()->pot)->toBe('cuenta');
});

it('app:cuota-efectivo rechaza un bolsillo inválido', function () {
    $m = Member::factory()->create();
    $code = Artisan::call('app:cuota-efectivo', ['nombre' => $m->user->name, 'lei' => 100, 'modo' => 'go', '--pot' => 'caja']);
    expect($code)->not->toBe(0)->and(Movement::withoutGlobalScopes()->count())->toBe(0);
});
