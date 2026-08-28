<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;
use Mollie\Api\MollieApiClient;

/**
 * Diagnóstico de Mollie: confirma que la API key funciona (auth) y lista los
 * miembros que quedaron con customer/subscription de MODO TEST (que en live no
 * existen y hacen fallar el checkout). Con --clear-customers los limpia.
 */
class MollieDiag extends Command
{
    protected $signature = 'mollie:diag {--clear-customers : Borra mollie_customer_id/subscription_id/subscription_status (los de test)}';

    protected $description = 'Diagnóstico de Mollie (auth + customers de test)';

    public function handle(MollieApiClient $mollie): int
    {
        $key = (string) config('services.mollie.key');
        $this->info('key prefix='.substr($key, 0, 5).' | enabled='.(int) config('services.mollie.enabled').' | profile='.config('services.mollie.profile_id'));

        try {
            $methods = $mollie->methods->allActive();
            $ids = collect($methods)->map(fn ($m) => $m->id)->implode(',');
            $this->info('AUTH OK — métodos activos: '.count($methods).' ('.$ids.')');
        } catch (\Throwable $e) {
            $this->error('AUTH FAIL: '.class_basename($e).' — '.$e->getMessage());
        }

        $withCustomer = Member::withoutGlobalScopes()
            ->whereNotNull('mollie_customer_id')
            ->get(['id', 'mollie_customer_id', 'mollie_subscription_id', 'subscription_status']);

        $this->info('miembros con mollie_customer_id: '.$withCustomer->count());
        foreach ($withCustomer as $m) {
            $this->line("  member {$m->id}: cust={$m->mollie_customer_id} sub={$m->mollie_subscription_id} status={$m->subscription_status}");
        }

        if ($this->option('clear-customers')) {
            $n = Member::withoutGlobalScopes()
                ->whereNotNull('mollie_customer_id')
                ->update([
                    'mollie_customer_id' => null,
                    'mollie_subscription_id' => null,
                    'subscription_status' => null,
                ]);
            $this->info("LIMPIADOS {$n} miembros (customer/subscription/status reseteados).");
        }

        return self::SUCCESS;
    }
}
