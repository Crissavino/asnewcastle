<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Due;
use App\Models\Payment;
use App\Services\Notifications;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        if ($event->type === 'checkout.session.completed') {
            $this->handleCheckoutCompleted($event->data->object);
        }

        return response('ok', 200);
    }

    protected function handleCheckoutCompleted(object $session): void
    {
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
        $isNew = DB::transaction(function () use ($due, $session, $paymentIntentId) {
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
        });

        // Campanita al manager: entró un pago (solo la primera vez, idempotente)
        if ($isNew) {
            app(Notifications::class)->paymentReceived($due);
        }
    }
}
