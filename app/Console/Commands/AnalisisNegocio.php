<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Foto de SOLO LECTURA de la economía del club: configuración de cuota, padrón,
 * cuotas generadas/cobradas por período, pagos, gastos por categoría y volumen
 * de actividad. Insumo para el análisis de negocio. No modifica nada.
 */
class AnalisisNegocio extends Command
{
    protected $signature = 'app:analisis-negocio';

    protected $description = 'Foto de solo lectura de la economía del club (cuotas, pagos, gastos, actividad)';

    public function handle(): int
    {
        foreach (Club::query()->get() as $club) {
            $this->info("CLUB #{$club->id} {$club->name} · {$club->city} · {$club->league}");
            $this->line("  cuota={$club->monthly_fee_cents} desc_sub={$club->subscription_discount_cents} moneda={$club->currency}");
        }

        $this->newLine();
        $this->info('PADRÓN:');
        $members = Member::withoutGlobalScopes()->with('user')->get();
        $this->line('  total filas members: '.$members->count());
        $this->line('  activos (sin baja, no ocultos): '.$members->whereNull('left_at')->where('hidden', false)->count());
        $this->line('  dados de baja: '.$members->whereNotNull('left_at')->count());
        $this->line('  ocultos: '.$members->where('hidden', true)->count());
        foreach ($members->whereNull('left_at')->groupBy('role') as $role => $g) {
            $this->line("  rol {$role}: ".$g->count());
        }
        foreach ($members->whereNull('left_at')->groupBy('fee_type') as $ft => $g) {
            $this->line('  fee_type '.($ft ?: 'null').': '.$g->count().' · montos custom: '.$g->pluck('custom_fee_cents')->filter()->implode(','));
        }
        $this->line('  con débito automático activo: '.$members->whereNull('left_at')->where('subscription_status', 'active')->count());
        foreach ($members->whereNull('left_at')->groupBy('subscription_status') as $st => $g) {
            $this->line('  subscription_status '.($st ?: 'null').': '.$g->count());
        }
        $this->line('  edades (birth_date presentes): '.$members->whereNull('left_at')->filter(fn ($m) => $m->user?->birth_date)->count());

        $this->newLine();
        $this->info('CUOTAS POR PERÍODO (monto en centavos):');
        $rows = DB::table('dues')
            ->selectRaw("period, count(*) as n, sum(amount_cents) as generado,
                sum(case when status='paid' then amount_cents else 0 end) as pagado,
                sum(case when status='pending' then amount_cents else 0 end) as pendiente,
                sum(case when status='waived' then amount_cents else 0 end) as becado,
                sum(status='paid') as n_pagadas, sum(status='pending') as n_pendientes, sum(status='waived') as n_becadas")
            ->groupBy('period')->orderBy('period')->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %s  n=%-3d generado=%-8d pagado=%-8d (%d) pendiente=%-8d (%d) becado=%-7d (%d)',
                $r->period, $r->n, $r->generado, $r->pagado, $r->n_pagadas, $r->pendiente, $r->n_pendientes, $r->becado, $r->n_becadas));
        }

        $this->newLine();
        $this->info('PAGOS (por mes de acreditación):');
        $rows = DB::table('payments')
            ->selectRaw("date_format(paid_at,'%Y-%m') as mes, provider, status, count(*) as n, sum(amount_cents) as monto, sum(application_fee_cents) as fee")
            ->groupBy('mes', 'provider', 'status')->orderBy('mes')->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %-8s %-8s %-10s n=%-3d monto=%-8d fee_plataforma=%d', $r->mes ?? 'sin fecha', $r->provider ?? '—', $r->status, $r->n, $r->monto, $r->fee));
        }

        $this->newLine();
        $this->info('GASTOS POR CATEGORÍA (histórico):');
        $rows = DB::table('expenses')->selectRaw('category, count(*) as n, sum(amount_cents) as monto')->groupBy('category')->orderByDesc('monto')->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %-10s n=%-3d monto=%d', $r->category, $r->n, $r->monto));
        }
        $this->info('GASTOS POR MES:');
        $rows = DB::table('expenses')->selectRaw("date_format(spent_on,'%Y-%m') as mes, count(*) as n, sum(amount_cents) as monto")->groupBy('mes')->orderBy('mes')->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %-8s n=%-3d monto=%d', $r->mes, $r->n, $r->monto));
        }

        $this->newLine();
        $this->info('CAJA (histórico, centavos):');
        $cobrado = (int) DB::table('dues')->where('status', 'paid')->sum('amount_cents');
        $gastado = (int) DB::table('expenses')->sum('amount_cents');
        $adeudado = (int) DB::table('dues')->where('status', 'pending')->sum('amount_cents');
        $this->line("  cobrado={$cobrado} gastado={$gastado} saldo=".($cobrado - $gastado)." adeudado_abierto={$adeudado}");

        $this->newLine();
        $this->info('ACTIVIDAD POR MES (eventos y respuestas):');
        $rows = DB::table('events')
            ->selectRaw("date_format(starts_at,'%Y-%m') as mes, kind, count(*) as n, sum(cancelled_at is not null) as cancelados, sum(goals_for is not null) as con_resultado")
            ->groupBy('mes', 'kind')->orderBy('mes')->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %-8s %-9s n=%-3d cancelados=%-2d con_resultado=%d', $r->mes, $r->kind, $r->n, $r->cancelados, $r->con_resultado));
        }
        $this->info('ASISTENCIA (sobre eventos no cancelados):');
        $rows = DB::table('attendances')->join('events', 'events.id', '=', 'attendances.event_id')
            ->whereNull('events.cancelled_at')
            ->selectRaw("events.kind, attendances.status, count(*) as n, sum(attendances.source='whatsapp') as por_whatsapp")
            ->groupBy('events.kind', 'attendances.status')->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %-9s %-6s n=%-4d whatsapp=%d', $r->kind, $r->status ?? 'sin responder', $r->n, $r->por_whatsapp));
        }
        $this->line('  presentes confirmados (attended=1): '.DB::table('attendances')->where('attended', 1)->count());
        $this->line('  eventos con presentes confirmados: '.DB::table('events')->whereNotNull('attendance_confirmed_at')->count());

        $this->newLine();
        $this->info('RESULTADOS DEPORTIVOS:');
        $r = DB::table('events')->where('kind', 'match')->whereNotNull('goals_for')->whereNull('cancelled_at')
            ->selectRaw('count(*) as n, sum(goals_for>goals_against) as g, sum(goals_for=goals_against) as e, sum(goals_for<goals_against) as p, sum(goals_for) as gf, sum(goals_against) as gc')->first();
        $this->line("  partidos con resultado={$r->n} G={$r->g} E={$r->e} P={$r->p} GF={$r->gf} GC={$r->gc}");

        $this->newLine();
        $this->info('VESTUARIO:');
        $this->line('  mensajes: '.DB::table('messages')->count().' (sistema: '.DB::table('messages')->where('is_system', 1)->count().')');
        $rows = DB::table('messages')->selectRaw("date_format(created_at,'%Y-%m') as mes, count(*) as n, count(distinct member_id) as autores")->groupBy('mes')->orderBy('mes')->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %-8s n=%-4d autores=%d', $r->mes, $r->n, $r->autores));
        }

        return self::SUCCESS;
    }
}
