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

// --- Débito automático (suscripciones) ---

function subInvoicePaid(Member $member, string $pi = 'pi_sub_1', int $amount = 25000): array
{
    return [
        'id' => 'evt_inv',
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => 'in_1',
            'object' => 'invoice',
            'subscription' => 'sub_'.$member->id,
            'customer' => 'cus_'.$member->id,
            'payment_intent' => $pi,
            'amount_paid' => $amount,
            'subscription_details' => ['metadata' => ['member_id' => (string) $member->id]],
        ]],
    ];
}

it('el checkout de suscripción vincula al jugador', function () {
    $member = Member::factory()->create();

    stripePost($this, [
        'id' => 'evt_cs',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_sub', 'object' => 'checkout.session', 'mode' => 'subscription',
            'subscription' => 'sub_'.$member->id, 'customer' => 'cus_'.$member->id,
            'metadata' => ['member_id' => (string) $member->id],
        ]],
    ])->assertOk();

    $member->refresh();
    expect($member->subscription_status)->toBe('active')
        ->and($member->stripe_subscription_id)->toBe('sub_'.$member->id)
        ->and($member->stripe_customer_id)->toBe('cus_'.$member->id);
});

it('invoice.paid marca la cuota del mes pagada y registra el pago', function () {
    $member = Member::factory()->create();
    $member->update(['stripe_subscription_id' => 'sub_'.$member->id, 'subscription_status' => 'active']);

    stripePost($this, subInvoicePaid($member, 'pi_sub_a', 25000))->assertOk();

    $due = Due::withoutGlobalScopes()->where('member_id', $member->id)->first();
    expect($due)->not->toBeNull()
        ->and($due->status)->toBe('paid')
        ->and($due->amount_cents)->toBe(25000)
        ->and(Payment::where('stripe_payment_intent_id', 'pi_sub_a')->count())->toBe(1);
});

it('invoice.paid es idempotente: reintento no duplica', function () {
    $member = Member::factory()->create();
    $member->update(['stripe_subscription_id' => 'sub_'.$member->id, 'subscription_status' => 'active']);

    stripePost($this, subInvoicePaid($member, 'pi_sub_rep'))->assertOk();
    stripePost($this, subInvoicePaid($member, 'pi_sub_rep'))->assertOk();

    expect(Payment::where('stripe_payment_intent_id', 'pi_sub_rep')->count())->toBe(1)
        ->and(Due::withoutGlobalScopes()->where('member_id', $member->id)->count())->toBe(1);
});

it('invoice.payment_failed deja la suscripción en past_due', function () {
    $member = Member::factory()->create();
    $member->update(['stripe_subscription_id' => 'sub_'.$member->id, 'subscription_status' => 'active']);

    stripePost($this, [
        'id' => 'evt_f', 'type' => 'invoice.payment_failed',
        'data' => ['object' => [
            'id' => 'in_f', 'subscription' => 'sub_'.$member->id,
            'subscription_details' => ['metadata' => ['member_id' => (string) $member->id]],
        ]],
    ])->assertOk();

    expect($member->fresh()->subscription_status)->toBe('past_due');
});

it('customer.subscription.deleted corta el débito automático', function () {
    $member = Member::factory()->create();
    $member->update(['stripe_subscription_id' => 'sub_'.$member->id, 'subscription_status' => 'active']);

    stripePost($this, [
        'id' => 'evt_d', 'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['id' => 'sub_'.$member->id, 'object' => 'subscription', 'status' => 'canceled']],
    ])->assertOk();

    $member->refresh();
    expect($member->subscription_status)->toBe('canceled')
        ->and($member->stripe_subscription_id)->toBeNull();
});
