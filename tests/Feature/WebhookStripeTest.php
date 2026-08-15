<?php

use App\Models\Due;
use App\Models\Member;
use App\Models\Payment;

beforeEach(function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);
});

function stripePost($test, array $event, ?string $signature = null)
{
    $payload = json_encode($event);

    if ($signature === null) {
        $t = time();
        $v1 = hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test');
        $signature = "t={$t},v1={$v1}";
    }

    return $test->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

function checkoutCompleted(Due $due, string $pi = 'pi_test_1'): array
{
    return [
        'id' => 'evt_1',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_test_1',
                'object' => 'checkout.session',
                'payment_intent' => $pi,
                'amount_total' => $due->amount_cents,
                'metadata' => ['due_id' => (string) $due->id],
            ],
        ],
    ];
}

it('rechaza un webhook de Stripe con firma inválida', function () {
    $due = Due::factory()->create();

    stripePost($this, checkoutCompleted($due), 't=1,v1=trucha')->assertStatus(400);

    expect($due->fresh()->status)->toBe('pending');
});

it('un checkout completado acredita la cuota y registra el pago', function () {
    config(['services.stripe.application_fee_bps' => 500]);
    $member = Member::factory()->create();
    $due = Due::factory()->forMember($member)->create(['amount_cents' => 12000]);

    stripePost($this, checkoutCompleted($due, 'pi_abc'))->assertOk();

    $due->refresh();
    expect($due->status)->toBe('paid');

    $payment = Payment::where('stripe_payment_intent_id', 'pi_abc')->first();
    expect($payment)->not->toBeNull()
        ->and($payment->due_id)->toBe($due->id)
        ->and($payment->amount_cents)->toBe(12000)
        ->and($payment->application_fee_cents)->toBe(600); // 5%
});

it('es idempotente por payment_intent: el reintento no duplica el pago', function () {
    $due = Due::factory()->create();

    stripePost($this, checkoutCompleted($due, 'pi_rep'))->assertOk();
    stripePost($this, checkoutCompleted($due, 'pi_rep'))->assertOk();

    expect(Payment::where('stripe_payment_intent_id', 'pi_rep')->count())->toBe(1)
        ->and($due->fresh()->status)->toBe('paid');
});

it('ignora eventos sin cuota conocida sin romper', function () {
    stripePost($this, [
        'id' => 'evt_x',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_x', 'payment_intent' => 'pi_x', 'metadata' => ['due_id' => '999999']]],
    ])->assertOk();

    expect(Payment::count())->toBe(0);
});
