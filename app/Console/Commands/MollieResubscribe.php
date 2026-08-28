<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\Mollie\MollieGateway;
use Illuminate\Console\Command;

/**
 * Reprograma el débito automático del dueño (OWNER_PHONE): cancela la
 * suscripción vigente y crea una nueva con el primer cobro en {start},
 * reusando el mandato. Se usa porque el pago del alta cuenta como septiembre,
 * así que el débito recurrente tiene que arrancar recién en octubre.
 *
 *   php artisan mollie:resubscribe {start=YYYY-MM-DD} {modo}
 *   modo=go ejecuta; cualquier otra cosa es simulacro.
 */
class MollieResubscribe extends Command
{
    protected $signature = 'mollie:resubscribe {start} {modo=dry}';

    protected $description = 'Cancela y recrea la suscripción del dueño con nuevo inicio';

    public function handle(MollieGateway $mollie): int
    {
        $member = Member::withoutGlobalScopes()->with(['user', 'club'])
            ->whereHas('user', fn ($q) => $q->where('phone', config('app.owner_phone')))
            ->first();

        if (! $member) {
            $this->error('dueño (OWNER_PHONE) no encontrado');

            return self::FAILURE;
        }

        $start = $this->argument('start');
        $go = $this->argument('modo') === 'go';

        $this->info("dueño #{$member->id} {$member->user?->name}");
        $this->line("  cust={$member->mollie_customer_id} sub actual={$member->mollie_subscription_id} status={$member->subscription_status}");
        $this->line("  monto suscripto={$member->subscribedFeeCents()} cents · nuevo inicio={$start}");

        if (! $go) {
            $this->warn('SIMULACRO. Pasá "go" para cancelar y recrear.');

            return self::SUCCESS;
        }

        $mollie->resubscribe($member->fresh(['user', 'club']), route('webhooks.mollie'), $start);

        $member->refresh();
        $this->info("LISTO. sub nueva={$member->mollie_subscription_id} status={$member->subscription_status} (primer cobro {$start})");

        return self::SUCCESS;
    }
}
