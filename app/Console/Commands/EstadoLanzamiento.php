<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Foto de solo lectura del estado de producción antes de limpiar para el
 * lanzamiento: los miembros (id, nombre, teléfono, rol, oculto, mollie) y
 * cuánta data de actividad hay para borrar. No modifica nada.
 */
class EstadoLanzamiento extends Command
{
    protected $signature = 'app:estado-lanzamiento';

    protected $description = 'Foto de solo lectura del club y sus miembros (para el lanzamiento)';

    public function handle(): int
    {
        foreach (Club::query()->get() as $club) {
            $this->info("CLUB #{$club->id} {$club->name} · fee={$club->monthly_fee_cents} desc={$club->subscription_discount_cents} standings=".($club->standings_json ? 'SÍ' : 'no'));
        }

        $this->newLine();
        $this->info('MIEMBROS (todos, incluidos ocultos):');
        $members = Member::withoutGlobalScopes()->with('user')->orderBy('id')->get();
        foreach ($members as $m) {
            $this->line(sprintf(
                '  #%d %-32s tel=%-16s rol=%-7s oculto=%s baja=%s | mollie cust=%s sub=%s status=%s',
                $m->id,
                $m->user?->name ?? '—',
                $m->user?->phone ?? '—',
                $m->role,
                $m->hidden ? 'SÍ' : 'no',
                $m->left_at ? 'SÍ' : 'no',
                $m->mollie_customer_id ?? '—',
                $m->mollie_subscription_id ?? '—',
                $m->subscription_status ?? '—',
            ));
        }

        $this->newLine();
        $this->info('DISPOSITIVOS (para push · usuarios distintos con token):');
        $devices = DB::table('device_tokens')
            ->selectRaw('platform, count(distinct user_id) as usuarios, count(*) as tokens')
            ->groupBy('platform')
            ->get();
        foreach ($devices as $d) {
            $this->line(sprintf('  %-10s %d usuarios (%d tokens)', $d->platform, $d->usuarios, $d->tokens));
        }
        $this->line('  usuarios únicos con algún dispositivo: '.DB::table('device_tokens')->distinct()->count('user_id'));

        $this->newLine();
        $this->info('DATA DE ACTIVIDAD (a borrar en la limpieza):');
        foreach (['events', 'attendances', 'messages', 'mvp_votes', 'player_ratings', 'expenses', 'dues', 'payments', 'registrations'] as $t) {
            $this->line(sprintf('  %-16s %d', $t, DB::table($t)->count()));
        }

        return self::SUCCESS;
    }
}
