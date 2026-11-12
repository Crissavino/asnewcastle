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
it('con secret configurado, una firma válida acredita', function () {
    config(['services.mollie.webhook_secret' => 'wh_secret_test']);
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'payout_firmado', amountCents: 28800, status: 'completed');

    $cuerpo = json_encode(['id' => 'event_x', 'entityId' => 'payout_firmado', 'type' => 'payout.completed']);
    $firma = 'sha256='.hash_hmac('sha256', $cuerpo, 'wh_secret_test');

    $this->call('POST', '/webhooks/mollie/payouts', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_MOLLIE_SIGNATURE' => $firma,
    ], $cuerpo)->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800);
});

it('con secret configurado, una firma falsa se rechaza y no acredita', function () {
    config(['services.mollie.webhook_secret' => 'wh_secret_test']);
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'payout_firmado', amountCents: 28800, status: 'completed');

    $cuerpo = json_encode(['id' => 'event_x', 'entityId' => 'payout_firmado', 'type' => 'payout.completed']);

    $this->call('POST', '/webhooks/mollie/payouts', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_MOLLIE_SIGNATURE' => 'sha256='.hash_hmac('sha256', $cuerpo, 'otro_secret'),
    ], $cuerpo)->assertForbidden();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0);
});

it('con secret configurado, sin firma tampoco pasa', function () {
    config(['services.mollie.webhook_secret' => 'wh_secret_test']);
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'payout_firmado', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['entityId' => 'payout_firmado'])
        ->assertForbidden();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0);
});

it('sin secret configurado no se verifica firma: poner el secret es un paso aparte', function () {
    config(['services.mollie.webhook_secret' => null]);
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'payout_sin_secret', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['entityId' => 'payout_sin_secret'])
        ->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800);
});

it('el webhook nuevo manda el payout en entityId y el evento en id: acredita igual', function () {
    // Formato real de los event destinations de Mollie: `id` es el id del
    // EVENTO. Leerlo como id de payout pedía un `event_...` inexistente y la
    // plata se perdía en silencio.
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'payout_real', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', [
        'id' => 'event_abc123',
        'entityId' => 'payout_real',
        'type' => 'payout.completed',
    ])->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800);
});

it('un webhook que solo trae el id del evento no rompe ni acredita nada', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'payout_real', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'event_abc123', 'type' => 'payout.completed'])
        ->assertOk();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0)
        ->and(MolliePayout::withoutGlobalScopes()->count())->toBe(0);
});

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

it('un payout que no viene en RON no se acredita, pero queda registrado', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed', currency: 'EUR');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    fijarClub($m);
    // No se acredita (no se suman euros como lei) pero el depósito existió en
    // el banco: sin fila no habría nada que mirar ni cartel que lo levante.
    $registro = MolliePayout::withoutGlobalScopes()->sole();
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0)
        ->and(Movement::withoutGlobalScopes()->count())->toBe(0)
        ->and($registro->club_id)->toBe($m->club_id)
        ->and($registro->amount_cents)->toBe(28800)
        ->and($registro->currency)->toBe('EUR')
        ->and($registro->pending_reason)->toBe('moneda')
        ->and($registro->noAcreditado())->toBeTrue();
});

it('un payout fallido se contra-asienta aunque el club ya no se resuelva', function () {
    // El dueño está en la fila desde que sí se resolvió: si el contra-asiento
    // dependiera del resolvedor, la app seguiría mostrando en `cuenta` un
    // depósito que el banco nunca hizo.
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    // Se da de alta un segundo club: desde ahora el resolvedor no sabe de quién
    // es la cuenta de Mollie.
    Club::factory()->create();

    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'failed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    fijarClub($m);
    $registro = MolliePayout::withoutGlobalScopes()->sole();
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0)
        ->and($registro->status)->toBe('failed')
        ->and($registro->club_id)->toBe($m->club_id)
        ->and(Movement::withoutGlobalScopes()->whereNotNull('reverses_movement_id')->count())->toBe(1);
});

it('un payout fallido sin club en la fila sigue quedando sin acreditar', function () {
    // Sin dueño escrito no hay nada que revertir: no se inventa un club.
    Member::factory()->manager()->create();
    Club::factory()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'failed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc'])->assertOk();

    expect(Movement::withoutGlobalScopes()->count())->toBe(0)
        ->and(MolliePayout::withoutGlobalScopes()->sole()->pending_reason)->toBe('club_ambiguo');
});

it('un webhook posterior a la adopción no re-acredita', function () {
    // La adopción (club_id null -> club resuelto) es el camino con más ramas de
    // acreditar(): el webhook se repite siempre, así que tiene que ser idempotente.
    $m = Member::factory()->manager()->create();
    Club::factory()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);

    config(['services.mollie.club_id' => $m->club_id]);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc']);
    $this->artisan('payouts:sincronizar');

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800)
        ->and(Movement::withoutGlobalScopes()->count())->toBe(1)
        ->and(MolliePayout::withoutGlobalScopes()->count())->toBe(1);
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
        ->and($registro->noAcreditado())->toBeTrue();
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

it('el tránsito suma disponible, entrante y saliente: un payout en curso no desaparece', function () {
    $balance = fakeMollieBalance(availableCents: 12000, pendingCents: 6000);
    $balance->outgoingAmount = mollieFakeMoney(3000);

    expect(app(MollieGateway::class)->transitCents())->toBe(21000);
});

it('un saliente ausente es cero, no "sin dato": Mollie omite el campo cuando no hay nada en curso', function () {
    // Confirmado contra la cuenta real (app:mollie-saldo-diag, 12.11.2026): el
    // saldo vino con availableAmount y SIN incomingAmount ni outgoingAmount.
    // Tratar la ausencia como "no sé" dejaba el tránsito en guion para siempre.
    $balance = fakeMollieBalance(availableCents: 12000, pendingCents: 6000);
    $balance->outgoingAmount = null;

    expect(app(MollieGateway::class)->transitCents())->toBe(18000);
});

it('un saldo en cero es cero, no null', function () {
    fakeMollieBalance(availableCents: 0, pendingCents: 0);

    expect(app(MollieGateway::class)->transitCents())->toBe(0);
});

it('un entrante ausente también es cero: queda el disponible', function () {
    $balance = fakeMollieBalance(availableCents: 12000, pendingCents: 6000);
    $balance->incomingAmount = null;

    expect(app(MollieGateway::class)->transitCents())->toBe(12000);
});

it('sin disponible legible SÍ es null y no cero: ahí no sabemos nada', function () {
    $balance = fakeMollieBalance(availableCents: 12000, pendingCents: 6000);
    $balance->availableAmount = null;

    expect(app(MollieGateway::class)->transitCents())->toBeNull();
});

it('un saldo en otra moneda es null: no se suman euros como lei', function () {
    fakeMollieBalance(availableCents: 12000, pendingCents: 6000, currency: 'EUR');

    expect(app(MollieGateway::class)->transitCents())->toBeNull();
});

it('un fallo se cachea corto: un minuto, no los cinco del éxito', function () {
    // El tránsito se lee sincrónico en cada carga de /cuota, de cada jugador:
    // sin cachear el fallo, una caída de Mollie le pega y espera el timeout en
    // cada request del plantel. Lo cacheado sigue siendo null —la pantalla
    // muestra el guion, nunca un cero—, solo se acorta el reintento.
    fakeMollieBalanceFailure();
    expect(app(MollieGateway::class)->transitCents())->toBeNull();

    fakeMollieBalance(availableCents: 500, pendingCents: 0);
    expect(app(MollieGateway::class)->transitCents())->toBeNull();

    $this->travel(61)->seconds();
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
it('trae un payout que el webhook se perdió', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayoutList([['id' => 'ptr_x', 'amountCents' => 28800, 'status' => 'completed']]);

    $this->artisan('payouts:sincronizar')->assertSuccessful();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800);

    $mov = Movement::withoutGlobalScopes()->where('mollie_payout_id', 'ptr_x')->get();
    expect($mov)->toHaveCount(1)->and($mov->first()->club_id)->toBe($m->club_id);
});

it('no duplica lo que el webhook ya acreditó', function () {
    // Review Focus #4
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_x', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_x', 'type' => 'payout.completed']);

    fakeMolliePayoutList([['id' => 'ptr_x', 'amountCents' => 28800, 'status' => 'completed']]);
    $this->artisan('payouts:sincronizar')->assertSuccessful();
    $this->artisan('payouts:sincronizar')->assertSuccessful();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800)
        ->and(Movement::withoutGlobalScopes()->where('mollie_payout_id', 'ptr_x')->count())->toBe(1);
});

it('el chequeo diario también contra-asienta un payout que falló', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_x', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_x']);

    fakeMolliePayoutList([['id' => 'ptr_x', 'amountCents' => 28800, 'status' => 'failed']]);
    $this->artisan('payouts:sincronizar')->assertSuccessful();

    fijarClub($m);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0);
});

it('si no puede listar los payouts falla a la vista y no acredita nada', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayoutListFailure();

    $this->artisan('payouts:sincronizar')->assertFailed();

    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('el chequeo diario corre a las 07:00 de Bucarest', function () {
    $evento = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'payouts:sincronizar'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('0 7 * * *')
        ->and($evento->timezone)->toBe('Europe/Bucharest');
});
