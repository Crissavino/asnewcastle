<?php

namespace App\Jobs;

use App\Models\Member;
use App\Services\Mollie\MollieGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Cancela la suscripción en Mollie en segundo plano, para que la app responda
 * al toque cuando el manager corta el débito. La fila ya quedó marcada como
 * "canceled"; acá nos aseguramos de que Mollie deje de cobrar.
 *
 * Guarda el subscriptionId explícito (no lo relee del member): si el jugador se
 * re-suscribe antes de que corra el job, no cancelamos la suscripción nueva.
 */
class CancelMollieSubscription implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $memberId,
        public string $customerId,
        public string $subscriptionId,
    ) {}

    public function handle(MollieGateway $mollie): void
    {
        try {
            $mollie->cancelSubscriptionById($this->customerId, $this->subscriptionId);
        } catch (\Throwable $e) {
            // Si ya estaba cancelada o no existe, seguimos y limpiamos la referencia.
            Log::warning('Mollie cancelSubscription '.$this->subscriptionId.': '.$e->getMessage());
        }

        // Limpiamos el id solo si sigue siendo el mismo (por si se re-suscribió).
        Member::where('id', $this->memberId)
            ->where('mollie_subscription_id', $this->subscriptionId)
            ->update(['mollie_subscription_id' => null]);
    }
}
