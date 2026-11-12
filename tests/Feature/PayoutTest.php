<?php

use App\Models\Club;
use App\Models\Member;
use App\Models\MolliePayout;
use App\Models\Movement;
use App\Services\Ledger;
use App\Services\Mollie\MollieGateway;

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

it('la comisión informada queda registrada pero NO se asienta: el monto del payout ya es neto', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed', deductionCents: 1200);

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    fijarClub($m);
    expect(Movement::withoutGlobalScopes()->where('kind', 'fee')->count())->toBe(0)
        ->and(app(Ledger::class)->balance('cuenta'))->toBe(28800)
        // Se registra para que se vea, pero no toca el saldo.
        ->and(MolliePayout::withoutGlobalScopes()->firstWhere('mollie_payout_id', 'ptr_abc')->fee_cents)->toBe(1200);
});

it('con el flag prendido la comisión se asienta aparte, negativa y en el mismo bolsillo', function () {
    config(['services.mollie.payout_fee_aparte' => true]);
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
    config(['services.mollie.payout_fee_aparte' => true]);
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

it('con dos clubes no adivina de quién es la plata, pero la deja registrada sin acreditar', function () {
    $m = Member::factory()->manager()->create();
    Club::factory()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    fijarClub($m);
    $registro = MolliePayout::withoutGlobalScopes()->sole();
    expect(Movement::withoutGlobalScopes()->count())->toBe(0)
        ->and($registro->club_id)->toBeNull()
        ->and($registro->amount_cents)->toBe(28800)
        ->and($registro->pending_reason)->toBe('club_ambiguo')
        ->and($registro->sinAcreditar())->toBeTrue();
});

it('sin ningún club la plata queda registrada sin acreditar, no en un log', function () {
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    expect(Movement::withoutGlobalScopes()->count())->toBe(0)
        ->and(MolliePayout::withoutGlobalScopes()->sole()->pending_reason)->toBe('sin_club');
});

it('el club configurado manda: con dos clubes acredita al de MOLLIE_CLUB_ID', function () {
    $m = Member::factory()->manager()->create();
    $otro = Member::factory()->manager()->create();
    config(['services.mollie.club_id' => $otro->club_id]);
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    fijarClub($otro);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800);
    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0);
});

it('un payout que quedó sin acreditar se acredita cuando el club se puede resolver', function () {
    // Es el camino del chequeo diario: pasa el mismo payout por el mismo sync.
    $m = Member::factory()->manager()->create();
    Club::factory()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    config(['services.mollie.club_id' => $m->club_id]);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    fijarClub($m);
    $registro = MolliePayout::withoutGlobalScopes()->sole();
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800)
        ->and($registro->club_id)->toBe($m->club_id)
        ->and($registro->pending_reason)->toBeNull();
});

it('un id desconocido no rompe el webhook', function () {
    fakeMolliePayoutFailure(code: 404);

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_nope'])->assertOk();

    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('un error transitorio de la API devuelve 503 para que Mollie reintente', function () {
    fakeMolliePayoutFailure(message: 'Mollie no responde', code: 500);

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertStatus(503);

    expect(Movement::withoutGlobalScopes()->count())->toBe(0)
        ->and(MolliePayout::withoutGlobalScopes()->count())->toBe(0);
});

it('un payout fallido se contra-asienta aunque venga sin monto legible ni moneda', function () {
    // El contra-asiento usa los importes de la base: no lo puede frenar un
    // payload roto, o quedaría acreditada plata que nunca llegó al banco.
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    $roto = fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'failed', currency: 'EUR');
    $roto->amount = null;
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0);
});

it('un webhook sin id no rompe nada', function () {
    $this->post('/webhooks/mollie/payouts', [])->assertOk();

    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('si Mollie no responde, el tránsito es null y no cero', function () {
    // Review Focus #5: un cero falso se lee como "no hay plata en camino".
    fakeMollieBalanceFailure();

    expect(app(MollieGateway::class)->transitCents())->toBeNull();
});

it('el tránsito es el saldo pendiente de Mollie', function () {
    fakeMollieBalance(availableCents: 12000, pendingCents: 6000);

    expect(app(MollieGateway::class)->transitCents())->toBe(18000);
});

it('un saldo en cero es cero, no null', function () {
    fakeMollieBalance(availableCents: 0, pendingCents: 0);

    expect(app(MollieGateway::class)->transitCents())->toBe(0);
});

it('un saldo sin la forma esperada es null y no cero', function () {
    $balance = fakeMollieBalance(availableCents: 12000, pendingCents: 6000);
    $balance->incomingAmount = null;

    expect(app(MollieGateway::class)->transitCents())->toBeNull();
});

it('un saldo en otra moneda es null: no se suman euros como lei', function () {
    fakeMollieBalance(availableCents: 12000, pendingCents: 6000, currency: 'EUR');

    expect(app(MollieGateway::class)->transitCents())->toBeNull();
});

it('un fallo no se cachea: la lectura siguiente vuelve a intentar', function () {
    fakeMollieBalanceFailure();
    expect(app(MollieGateway::class)->transitCents())->toBeNull();

    fakeMollieBalance(availableCents: 500, pendingCents: 0);
    expect(app(MollieGateway::class)->transitCents())->toBe(500);
});

it('un saldo leído se cachea cinco minutos', function () {
    fakeMollieBalance(availableCents: 500, pendingCents: 0);
    expect(app(MollieGateway::class)->transitCents())->toBe(500);

    fakeMollieBalance(availableCents: 9999, pendingCents: 0);
    expect(app(MollieGateway::class)->transitCents())->toBe(500);

    $this->travel(6)->minutes();
    expect(app(MollieGateway::class)->transitCents())->toBe(9999);
});
