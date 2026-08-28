<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\Mollie\MollieGateway;
use Illuminate\Console\Command;

/**
 * Reprograma el débito automático de un miembro: cancela la suscripción
 * vigente y crea una nueva con el primer cobro en {start}. Reusa el mandato.
 * Positional args (sin flags, por el autocomplete de Forge):
 *   php artisan mollie:resubscribe {member_id} {start=YYYY-MM-DD} {modo}
 *   modo=go ejecuta; cualquier otra cosa muestra el estado actual (dry-run).
 */
class MollieResubscribe extends Command
{
    protected $signature = 'mollie:resubscribe {member_id} {start} {modo=dry}';

    protected $description = 'Cancela y recrea la suscripción de un miembro con nuevo inicio';

    public function handle(MollieGateway $mollie): int
    {
        $member = Member::withoutGlobalScopes()->with(['user', 'club'])->find((int) $this->argument('member_id'));

        if (! $member) {
            $this->error('member no encontrado');

            return self::FAILURE;
        }

        $start = $this->argument('start');
        $go = $this->argument('modo') === 'go';

        $this->info("member #{$member->id} {$member->user?->name}");
        $this->line("  cust={$member->mollie_customer_id} sub actual={$member->mollie_subscription_id} status={$member->subscription_status}");
        $this->line("  monto suscripto={$member->subscribedFeeCents()} cents · nuevo inicio={$start}");

        if (! $go) {
            $this->warn('SIMULACRO. Pasá "go" como 3er argumento para cancelar y recrear.');

            return self::SUCCESS;
        }

        $mollie->resubscribe($member->fresh(['user', 'club']), route('webhooks.mollie'), $start);

        $member->refresh();
        $this->info("LISTO. sub nueva={$member->mollie_subscription_id} status={$member->subscription_status} (primer cobro {$start})");

        return self::SUCCESS;
    }
}
