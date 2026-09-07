<?php

use App\Models\Due;
use App\Models\Member;
use App\Models\Payment;
use App\Services\Mollie\MollieGateway;

/**
 * Un Payment de Mollie de mentira: extiende el recurso real (para cumplir el
 * return type de MollieGateway::getPayment) con constructor vacío y estado por
 * status. Solo trae lo que lee el webhook.
 */
function molliePayment(array $attrs): \Mollie\Api\Resources\Payment
{
    $p = new class extends \Mollie\Api\Resources\Payment
    {
        public function __construct() {}

        public function isPaid(): bool
        {
            return $this->status === 'paid';
        }

        public function isFailed(): bool
        {
            return $this->status === 'failed';
        }

        public function isCanceled(): bool
        {
            return $this->status === 'canceled';
        }

        public function isExpired(): bool
        {
            return $this->status === 'expired';
        }
    };

    $p->id = $attrs['id'] ?? 'tr_test';
    $p->status = $attrs['status'] ?? 'paid';
    $p->amount = (object) ['value' => $attrs['value'] ?? '120.00', 'currency' => 'RON'];
    $p->metadata = (object) ($attrs['metadata'] ?? []);
    $p->subscriptionId = $attrs['subscriptionId'] ?? null;
    $p->customerId = $attrs['customerId'] ?? null;
    $p->createdAt = $attrs['createdAt'] ?? now()->toIso8601String();

    return $p;
}

/** Mockea el gateway para que getPayment($id) devuelva $payment. */
function fakeMollie(object $payment, ?Closure $onStart = null): void
{
    $mock = Mockery::mock(MollieGateway::class);
    $mock->shouldReceive('getPayment')->andReturn($payment);
    $mock->shouldReceive('startSubscription')->andReturnUsing($onStart ?? fn () => null);
    app()->instance(MollieGateway::class, $mock);
}

it('un pago puntual paga la cuota y registra el pago', function () {
    $member = Member::factory()->create();
    $due = Due::factory()->forMember($member)->create(['amount_cents' => 12000, 'status' => 'pending']);

    fakeMollie(molliePayment([
        'id' => 'tr_one', 'value' => '120.00',
        'metadata' => ['due_id' => (string) $due->id],
    ]));

    $this->post('/webhooks/mollie', ['id' => 'tr_one'])->assertOk();

    expect($due->fresh()->status)->toBe('paid');
    $payment = Payment::where('mollie_payment_id', 'tr_one')->first();
    expect($payment)->not->toBeNull()
        ->and($payment->provider)->toBe('mollie')
        ->and($payment->due_id)->toBe($due->id)
        ->and($payment->amount_cents)->toBe(12000);
});

it('es idempotente por mollie_payment_id: el reintento no duplica', function () {
    $member = Member::factory()->create();
    $due = Due::factory()->forMember($member)->create(['amount_cents' => 12000, 'status' => 'pending']);
    $payload = molliePayment(['id' => 'tr_dup', 'metadata' => ['due_id' => (string) $due->id]]);

    fakeMollie($payload);
    $this->post('/webhooks/mollie', ['id' => 'tr_dup'])->assertOk();
    fakeMollie($payload);
    $this->post('/webhooks/mollie', ['id' => 'tr_dup'])->assertOk();

    expect(Payment::where('mollie_payment_id', 'tr_dup')->count())->toBe(1);
});

it('el primer pago crea la suscripción y paga el mes en curso', function () {
    $member = Member::factory()->create(['mollie_customer_id' => 'cst_x']);

    fakeMollie(
        molliePayment([
            'id' => 'tr_first', 'value' => '120.00', 'customerId' => 'cst_x',
            'metadata' => ['member_id' => (string) $member->id, 'purpose' => 'subscription_first'],
        ]),
        onStart: function ($m) {
            $m->update(['mollie_subscription_id' => 'sub_x', 'subscription_status' => 'active']);
        },
    );

    $this->post('/webhooks/mollie', ['id' => 'tr_first'])->assertOk();

    // La cuota del mes en curso quedó paga
    $due = Due::where('member_id', $member->id)
        ->whereDate('period', now()->startOfMonth())->first();
    expect($due)->not->toBeNull()->and($due->status)->toBe('paid');

    // Y arrancó el débito automático
    expect($member->fresh()->subscription_status)->toBe('active')
        ->and($member->fresh()->mollie_subscription_id)->toBe('sub_x');
});

it('un cobro mensual marca la cuota del período pagada', function () {
    $member = Member::factory()->create([
        'mollie_subscription_id' => 'sub_y', 'subscription_status' => 'active',
    ]);

    fakeMollie(molliePayment([
        'id' => 'tr_rec', 'value' => '120.00', 'subscriptionId' => 'sub_y',
        'createdAt' => now()->startOfMonth()->toIso8601String(),
    ]));

    $this->post('/webhooks/mollie', ['id' => 'tr_rec'])->assertOk();

    $due = Due::where('member_id', $member->id)
        ->whereDate('period', now()->startOfMonth())->first();
    expect($due)->not->toBeNull()->and($due->status)->toBe('paid');
    expect(Payment::where('mollie_payment_id', 'tr_rec')->first()?->provider)->toBe('mollie');
});

it('un pago no pagado no acredita nada', function () {
    $member = Member::factory()->create();
    $due = Due::factory()->forMember($member)->create(['status' => 'pending']);

    fakeMollie(molliePayment([
        'id' => 'tr_open', 'status' => 'open',
        'metadata' => ['due_id' => (string) $due->id],
    ]));

    $this->post('/webhooks/mollie', ['id' => 'tr_open'])->assertOk();

    expect($due->fresh()->status)->toBe('pending')
        ->and(Payment::where('mollie_payment_id', 'tr_open')->exists())->toBeFalse();
});

it('un cobro recurrente fallido deja la suscripción en past_due', function () {
    $member = Member::factory()->create([
        'mollie_subscription_id' => 'sub_z', 'subscription_status' => 'active',
    ]);

    fakeMollie(molliePayment([
        'id' => 'tr_fail', 'status' => 'failed', 'subscriptionId' => 'sub_z',
    ]));

    $this->post('/webhooks/mollie', ['id' => 'tr_fail'])->assertOk();

    expect($member->fresh()->subscription_status)->toBe('past_due');
});

it('si el mes en curso ya está pago, el alta cubre el mes siguiente (nada de cobrar doble)', function () {
    $member = Member::factory()->create(['mollie_customer_id' => 'cst_re']);

    // Septiembre ya lo pagó (p. ej. con la suscripción que le cancelaron)
    Due::factory()->create([
        'club_id' => $member->club_id,
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'status' => 'paid',
    ]);

    $startDates = [];
    fakeMollie(
        molliePayment([
            'id' => 'tr_re', 'value' => '120.00', 'customerId' => 'cst_re',
            'metadata' => ['member_id' => (string) $member->id, 'purpose' => 'subscription_first'],
        ]),
        onStart: function ($m, $url, $startDate = null) use (&$startDates) {
            $startDates[] = $startDate;
        },
    );

    $this->post('/webhooks/mollie', ['id' => 'tr_re'])->assertOk();

    // El alta se imputó al mes que viene, no al que ya estaba pago
    $next = now()->addMonthNoOverflow()->startOfMonth();
    $due = Due::where('member_id', $member->id)->whereDate('period', $next)->first();
    expect($due)->not->toBeNull()->and($due->status)->toBe('paid')
        // Y el pago quedó colgado del mes nuevo, no del viejo
        ->and(Payment::where('mollie_payment_id', 'tr_re')->first()->due_id)->toBe($due->id)
        // El débito arranca un mes después del que cubrió el alta
        ->and($startDates)->toBe([$next->copy()->addMonthNoOverflow()->toDateString()]);
});

it('el reintento del webhook del alta no corre la cuota a otro mes', function () {
    $member = Member::factory()->create(['mollie_customer_id' => 'cst_rt']);

    $payload = molliePayment([
        'id' => 'tr_retry', 'value' => '120.00', 'customerId' => 'cst_rt',
        'metadata' => ['member_id' => (string) $member->id, 'purpose' => 'subscription_first'],
    ]);

    fakeMollie($payload);
    $this->post('/webhooks/mollie', ['id' => 'tr_retry'])->assertOk();
    fakeMollie($payload);
    $this->post('/webhooks/mollie', ['id' => 'tr_retry'])->assertOk();

    // Una sola cuota (la del mes en curso) y un solo pago: el reintento no
    // "avanza" al mes siguiente aunque el mes en curso ya figure pago
    expect(Due::where('member_id', $member->id)->count())->toBe(1)
        ->and(Due::where('member_id', $member->id)->first()->period->isSameMonth(now()))->toBeTrue()
        ->and(Payment::where('mollie_payment_id', 'tr_retry')->count())->toBe(1);
});
