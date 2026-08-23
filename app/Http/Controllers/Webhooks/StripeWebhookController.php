<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Due;
use App\Models\Member;
use App\Models\Payment;
use App\Services\Notifications;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('Firma inválida', 400);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'invoice.paid' => $this->handleInvoicePaid($event->data->object),
            'invoice.payment_failed' => $this->handleInvoiceFailed($event->data->object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
            default => null,
        };

        return response('ok', 200);
    }

    protected function handleCheckoutCompleted(object $session): void
    {
        // Débito automático: el checkout de suscripción vincula al jugador.
        if (($session->mode ?? null) === 'subscription' || ! empty($session->subscription)) {
            $this->linkSubscription($session);

            return;
        }

        $dueId = $session->metadata->due_id ?? null;
        $paymentIntentId = $session->payment_intent ?? null;

        if (! $dueId || ! $paymentIntentId) {
            return;
        }

        $due = Due::withoutGlobalScopes()->find($dueId);

        if (! $due) {
            return;
        }

        // Idempotencia: el unique de stripe_payment_intent_id hace que un
        // reintento del webhook no duplique el pago ni el estado.
        DB::transaction(function () use ($due, $session, $paymentIntentId) {
            $payment = Payment::firstOrCreate(
                ['stripe_payment_intent_id' => $paymentIntentId],
                [
                    'due_id' => $due->id,
                    'amount_cents' => (int) ($session->amount_total ?? $due->amount_cents),
                    'application_fee_cents' => (int) round(
                        $due->amount_cents * config('services.stripe.application_fee_bps') / 10000
                    ),
                    'status' => 'succeeded',
                    'paid_at' => now(),
                ],
            );

            if ($payment->wasRecentlyCreated && $due->isPending()) {
                $due->update(['status' => 'paid']);
            }

            return $payment->wasRecentlyCreated;
        }) && $this->notifyPayment($due);
    }

    /** Vincula la suscripción recién creada al jugador (customer + subscription). */
    protected function linkSubscription(object $session): void
    {
        $memberId = $session->metadata->member_id ?? null;

        if (! $memberId || ! ($member = Member::find($memberId))) {
            return;
        }

        $member->update([
            'stripe_customer_id' => $session->customer ?? $member->stripe_customer_id,
            'stripe_subscription_id' => $session->subscription ?? $member->stripe_subscription_id,
            'subscription_status' => 'active',
        ]);
    }

    /**
     * Cada mes que Stripe cobra la suscripción llega este evento: marcamos la
     * cuota del período como pagada y registramos el pago (idempotente).
     */
    protected function handleInvoicePaid(object $invoice): void
    {
        $member = $this->memberForInvoice($invoice);

        if (! $member) {
            return;
        }

        // Si el invoice llegó antes que el checkout, dejamos el vínculo igual
        if (! $member->isSubscribed() || ! $member->stripe_subscription_id) {
            $member->update([
                'stripe_subscription_id' => $invoice->subscription ?? $member->stripe_subscription_id,
                'stripe_customer_id' => $invoice->customer ?? $member->stripe_customer_id,
                'subscription_status' => 'active',
            ]);
        }

        $amount = (int) ($invoice->amount_paid ?? 0);
        $piKey = $invoice->payment_intent ?? ('inv_'.($invoice->id ?? uniqid()));

        $periodStart = data_get($invoice, 'lines.data.0.period.start') ?? ($invoice->period_start ?? null);
        $period = $periodStart
            ? Carbon::createFromTimestamp($periodStart)->startOfMonth()
            : now()->startOfMonth();

        [$due, $isNew] = DB::transaction(function () use ($member, $period, $amount, $piKey) {
            // El período va como Carbon (no string) para que matchee el formato
            // datetime guardado por el cast 'date' y no reintente el insert.
            $due = Due::withoutGlobalScopes()->updateOrCreate(
                ['club_id' => $member->club_id, 'member_id' => $member->id, 'period' => $period],
                ['amount_cents' => $amount, 'status' => 'paid', 'due_date' => $period->copy()->day(20)->toDateString()],
            );

            $payment = Payment::firstOrCreate(
                ['stripe_payment_intent_id' => $piKey],
                [
                    'due_id' => $due->id,
                    'amount_cents' => $amount,
                    'application_fee_cents' => (int) round($amount * config('services.stripe.application_fee_bps') / 10000),
                    'status' => 'succeeded',
                    'paid_at' => now(),
                ],
            );

            return [$due, $payment->wasRecentlyCreated];
        });

        // Solo la primera vez: no duplicar el aviso en reintentos del webhook
        if ($isNew) {
            $this->notifyPayment($due);
        }
    }

    protected function handleInvoiceFailed(object $invoice): void
    {
        $this->memberForInvoice($invoice)?->update(['subscription_status' => 'past_due']);
    }

    protected function handleSubscriptionDeleted(object $subscription): void
    {
        Member::where('stripe_subscription_id', $subscription->id)->first()
            ?->update(['subscription_status' => 'canceled', 'stripe_subscription_id' => null]);
    }

    protected function handleSubscriptionUpdated(object $subscription): void
    {
        $member = Member::where('stripe_subscription_id', $subscription->id)->first();

        if (! $member) {
            return;
        }

        $status = match ($subscription->status ?? '') {
            'active', 'trialing' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'canceled' => 'canceled',
            default => $member->subscription_status,
        };

        $member->update(['subscription_status' => $status]);
    }

    /** Encuentra al jugador de un invoice por metadata del member o por la subscription. */
    protected function memberForInvoice(object $invoice): ?Member
    {
        $memberId = data_get($invoice, 'subscription_details.metadata.member_id')
            ?? data_get($invoice, 'lines.data.0.metadata.member_id')
            ?? data_get($invoice, 'metadata.member_id');

        if ($memberId && ($member = Member::find($memberId))) {
            return $member;
        }

        $subscriptionId = $invoice->subscription ?? null;

        return $subscriptionId ? Member::where('stripe_subscription_id', $subscriptionId)->first() : null;
    }

    /** Aviso al manager de que entró un pago (idempotente: solo la 1ª vez). */
    protected function notifyPayment(Due $due): void
    {
        app(Notifications::class)->paymentReceived($due);
    }
}
