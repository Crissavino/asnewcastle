<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Mollie\MollieGateway;
use App\Services\PayoutLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de payouts de Mollie: cada vez que Mollie deposita en el banco del
 * club lo que juntó de las cuotas. Es la ÚNICA entrada de plata a `cuenta`.
 *
 * Mismo patrón que MollieWebhookController: Mollie no firma el webhook, manda
 * solo el id y la verificación es traer el payout por API (la fuente de verdad
 * es el estado que devuelve Mollie, no lo que llega en el body). Idempotente
 * por mollie_payout_id. Un error de API devuelve 200: el chequeo diario lo
 * repesca pasando el payout por el mismo PayoutLedger.
 *
 * Corre sin sesión, sin usuario y sin el middleware de club: el club lo
 * resuelve PayoutLedger a mano.
 */
class MolliePayoutWebhookController extends Controller
{
    public function __invoke(Request $request, MollieGateway $mollie, PayoutLedger $ledger): Response
    {
        $payoutId = (string) $request->input('id');

        if ($payoutId === '') {
            return response('ok', 200);
        }

        try {
            $payout = $mollie->getPayout($payoutId);
        } catch (\Throwable $e) {
            // Id desconocido / error transitorio: no romper.
            Log::warning('No se pudo traer el payout de Mollie', [
                'payout' => $payoutId,
                'error' => $e->getMessage(),
            ]);

            return response('ok', 200);
        }

        Log::info('Webhook de payout de Mollie', [
            'payout' => $payoutId,
            'resultado' => $ledger->sync($payout),
        ]);

        return response('ok', 200);
    }
}
