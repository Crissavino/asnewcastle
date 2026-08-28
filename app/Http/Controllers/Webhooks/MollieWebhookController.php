<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Due;
use App\Models\Member;
use App\Models\Payment;
use App\Services\Mollie\MollieGateway;
use App\Services\Notifications;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Webhook de Mollie. Mollie NO firma el webhook: manda solo el id del pago y la
 * verificación es traer el pago por API (la fuente de verdad es el estado que
 * devuelve Mollie, no lo que llega en el body). Idempotente por mollie_payment_id.
 *
 * Tres casos de un pago `paid`:
 *  - tiene subscriptionId  -> cobro mensual automático: marca la cuota del período
 *  - metadata.purpose=subscription_first -> alta del débito: crea la subscription
 *    y marca el mes en curso (ese primer pago ya lo cubre)
 *  - metadata.due_id -> pago puntual de una cuota pendiente
 */
class MollieWebhookController extends Controller
{
    public function __invoke(Request $request, MollieGateway $mollie): Response
    {
        $paymentId = (string) $request->input('id');

        if ($paymentId === '') {
            return response('ok', 200);
        }

        try {
            $payment = $mollie->getPayment($paymentId);
        } catch (\Throwable) {
            // Id desconocido / error transitorio: no romper (Mollie reintenta)
            return response('ok', 200);
        }

        $meta = $payment->metadata ?? null;
        $memberId = isset($meta->member_id) ? (int) $meta->member_id : null;
        $dueId = isset($meta->due_id) ? (int) $meta->due_id : null;
        $purpose = $meta->purpose ?? null;
        $subscriptionId = $payment->subscriptionId ?? null;

        if (! $payment->isPaid()) {
            // Un cobro recurrente que falló deja la suscripción en past_due
            if (($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) && $subscriptionId) {
                Member::where('mollie_subscription_id', $subscriptionId)->first()
                    ?->update(['subscription_status' => 'past_due']);
            }

            return response('ok', 200);
        }

        $amountCents = (int) round(((float) $payment->amount->value) * 100);

        if ($subscriptionId) {
            $this->handleRecurring($payment, $subscriptionId, $amountCents);
        } elseif ($purpose === 'subscription_first' && $memberId) {
            $this->handleFirst($payment, $memberId, $amountCents, $mollie);
        } elseif ($dueId) {
            $this->handleOneoff($payment, $dueId, $amountCents);
        }

        return response('ok', 200);
    }

    /** Pago puntual de una cuota: la marca pagada y registra el pago. */
    protected function handleOneoff(object $payment, int $dueId, int $amountCents): void
    {
        $due = Due::withoutGlobalScopes()->find($dueId);

        if (! $due) {
            return;
        }

        $isNew = DB::transaction(function () use ($due, $payment, $amountCents) {
            $record = $this->recordPayment($payment->id, $due, $amountCents);

            if ($record->wasRecentlyCreated && $due->isPending()) {
                $due->update(['status' => 'paid']);
            }

            return $record->wasRecentlyCreated;
        });

        if ($isNew) {
            $this->notify($due);
        }
    }

    /**
     * Primer pago del alta: marca el mes en curso y crea la suscripción mensual.
     */
    protected function handleFirst(object $payment, int $memberId, int $amountCents, MollieGateway $mollie): void
    {
        $member = Member::find($memberId);

        if (! $member) {
            return;
        }

        if ($payment->customerId && ! $member->mollie_customer_id) {
            $member->update(['mollie_customer_id' => $payment->customerId]);
        }

        $period = now()->startOfMonth();

        $isNew = DB::transaction(function () use ($member, $period, $payment, $amountCents) {
            $due = Due::withoutGlobalScopes()->updateOrCreate(
                ['club_id' => $member->club_id, 'member_id' => $member->id, 'period' => $period],
                ['amount_cents' => $amountCents, 'status' => 'paid', 'due_date' => $period->copy()->day(20)->toDateString()],
            );

            $record = $this->recordPayment($payment->id, $due, $amountCents);

            return $record->wasRecentlyCreated ? $due : false;
        });

        // El mandato ya es válido: arrancamos el débito automático (idempotente)
        $mollie->startSubscription($member->fresh(), route('webhooks.mollie'));

        if ($isNew) {
            $this->notify($isNew);
        }
    }

    /** Cobro mensual automático: marca la cuota del período y registra el pago. */
    protected function handleRecurring(object $payment, string $subscriptionId, int $amountCents): void
    {
        $member = Member::where('mollie_subscription_id', $subscriptionId)->first();

        if (! $member) {
            return;
        }

        if ($member->subscription_status !== 'active') {
            $member->update(['subscription_status' => 'active']);
        }

        $period = ($payment->createdAt ? Carbon::parse($payment->createdAt) : now())->startOfMonth();

        $isNew = DB::transaction(function () use ($member, $period, $payment, $amountCents) {
            $due = Due::withoutGlobalScopes()->updateOrCreate(
                ['club_id' => $member->club_id, 'member_id' => $member->id, 'period' => $period],
                ['amount_cents' => $amountCents, 'status' => 'paid', 'due_date' => $period->copy()->day(20)->toDateString()],
            );

            $record = $this->recordPayment($payment->id, $due, $amountCents);

            return $record->wasRecentlyCreated ? $due : false;
        });

        if ($isNew) {
            $this->notify($isNew);
        }
    }

    /** firstOrCreate por mollie_payment_id: el reintento del webhook no duplica. */
    protected function recordPayment(string $molliePaymentId, Due $due, int $amountCents): Payment
    {
        return Payment::firstOrCreate(
            ['mollie_payment_id' => $molliePaymentId],
            [
                'provider' => 'mollie',
                'due_id' => $due->id,
                'amount_cents' => $amountCents,
                'application_fee_cents' => 0,
                'status' => 'succeeded',
                'paid_at' => now(),
            ],
        );
    }

    protected function notify(Due $due): void
    {
        app(Notifications::class)->paymentReceived($due);
    }
}
