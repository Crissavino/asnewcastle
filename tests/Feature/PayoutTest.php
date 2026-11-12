<?php

use App\Models\Club;
use App\Models\Member;
use App\Models\MolliePayout;
use App\Models\Movement;
use App\Services\Ledger;

/**
 * Los payouts son la ÚNICA entrada de plata a la cuenta del club: Mollie junta
 * los pagos de las cuotas y deposita el neto en el IBAN. El webhook corre sin
 * request ni usuario, así que acá el club activo se fija a mano (fijarClub)
 * para leer el Ledger, igual que lo haría el middleware en una request.
 */
it('un payout completado acredita el neto en la cuenta', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.completed'])
        ->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800)
        ->and(app(Ledger::class)->balance('cash'))->toBe(0);

    $payout = MolliePayout::withoutGlobalScopes()->firstWhere('mollie_payout_id', 'ptr_abc');
    expect($payout)->not->toBeNull()
        ->and($payout->status)->toBe('completed')
        ->and($payout->amount_cents)->toBe(28800)
        ->and($payout->club_id)->toBe($m->club_id);
});

it('el movimiento del payout queda atado al club dueño de la cuenta', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    $mov = Movement::withoutGlobalScopes()->where('kind', 'payout')->sole();
    expect($mov->club_id)->toBe($m->club_id)
        ->and($mov->pot)->toBe('cuenta')
        ->and($mov->mollie_payout_id)->toBe('ptr_abc');
});

it('el mismo payout dos veces acredita una sola vez', function () {
    // Review Focus #4
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.completed']);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.completed']);

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800)
        ->and(MolliePayout::withoutGlobalScopes()->count())->toBe(1)
        ->and(Movement::withoutGlobalScopes()->count())->toBe(1);
});

it('un payout fallido contra-asienta lo acreditado', function (string $status) {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.completed']);

    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: $status);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.failed'])
        ->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0)
        ->and(MolliePayout::withoutGlobalScopes()->firstWhere('mollie_payout_id', 'ptr_abc')->status)->toBe($status)
        // El contra-asiento es una fila nueva: las filas del libro no se borran
        ->and(Movement::withoutGlobalScopes()->whereNotNull('reverses_movement_id')->count())->toBe(1);
})->with(['failed', 'canceled']);

it('el contra-asiento de un payout fallido no se duplica', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'failed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0)
        ->and(Movement::withoutGlobalScopes()->count())->toBe(2);
});

it('si Mollie trae la comisión la asienta aparte, negativa y en el mismo bolsillo', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed', deductionCents: 1200);

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    fijarClub($m);
    $fee = Movement::withoutGlobalScopes()->where('kind', 'fee')->sole();
    expect($fee->amount_cents)->toBe(-1200)
        ->and($fee->pot)->toBe('cuenta')
        ->and($fee->club_id)->toBe($m->club_id)
        ->and(app(Ledger::class)->balance('cuenta'))->toBe(27600)
        ->and(MolliePayout::withoutGlobalScopes()->firstWhere('mollie_payout_id', 'ptr_abc')->fee_cents)->toBe(1200);
});

it('un payout fallido contra-asienta también la comisión', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed', deductionCents: 1200);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'failed', deductionCents: 1200);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0)
        ->and(Movement::withoutGlobalScopes()->count())->toBe(4);
});

it('si la comisión no viene no la inventa', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    fijarClub($m);
    expect(Movement::withoutGlobalScopes()->where('kind', 'fee')->count())->toBe(0)
        ->and(MolliePayout::withoutGlobalScopes()->firstWhere('mollie_payout_id', 'ptr_abc')->fee_cents)->toBeNull()
        ->and(app(Ledger::class)->balance('cuenta'))->toBe(28800);
});

it('un payout que no viene en RON no se acredita', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed', currency: 'EUR');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0)
        ->and(Movement::withoutGlobalScopes()->count())->toBe(0)
        ->and(MolliePayout::withoutGlobalScopes()->count())->toBe(0);
});

it('un payout todavía en camino no acredita nada', function (string $status) {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: $status);

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0)
        ->and(Movement::withoutGlobalScopes()->count())->toBe(0);
})->with(['requested', 'initiated', 'processing-at-bank']);

it('con dos clubes candidatos no acredita nada: no se sabe de quién es la plata', function () {
    $m = Member::factory()->manager()->create();
    Club::factory()->create(['monthly_fee_cents' => 9000]);
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    fijarClub($m);
    expect(Movement::withoutGlobalScopes()->count())->toBe(0)
        ->and(MolliePayout::withoutGlobalScopes()->count())->toBe(0);
});

it('un id desconocido no rompe el webhook', function () {
    fakeMolliePayoutFailure();

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_nope'])->assertOk();

    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('un webhook sin id no rompe nada', function () {
    $this->post('/webhooks/mollie/payouts', [])->assertOk();

    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});
