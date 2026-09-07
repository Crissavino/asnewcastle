<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Solo lectura: para un jugador (por nombre), lista sus cuotas y quién
 * marcó a mano cada cambio de estado (audit_logs, acción due.status.set).
 */
class QuienMarcoCuota extends Command
{
    protected $signature = 'app:quien-marco {nombre=Dorin}';

    protected $description = 'Quién marcó a mano las cuotas de un jugador (solo lectura)';

    public function handle(): int
    {
        $nombre = $this->argument('nombre');

        $this->info('CUOTAS:');
        $dues = DB::table('dues as d')
            ->join('members as m', 'm.id', '=', 'd.member_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('payments as p', 'p.due_id', '=', 'd.id')
            ->where('u.name', 'like', "%{$nombre}%")
            ->orderBy('d.period')
            ->get(['d.id', 'u.name as jugador', 'd.period', 'd.status', 'p.id as pago_online']);
        foreach ($dues as $d) {
            $this->line("  due #{$d->id} {$d->jugador} · {$d->period} · {$d->status} · pago online: ".($d->pago_online ? "SÍ (#{$d->pago_online})" : 'no'));
        }

        $this->newLine();
        $this->info('AUDITORÍA (due.status.set):');
        $logs = DB::table('audit_logs as a')
            ->join('dues as d', 'd.id', '=', 'a.subject_id')
            ->join('members as m', 'm.id', '=', 'd.member_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('members as am', 'am.id', '=', 'a.actor_member_id')
            ->leftJoin('users as au', 'au.id', '=', 'am.user_id')
            ->where('a.action', 'due.status.set')
            ->where('u.name', 'like', "%{$nombre}%")
            ->orderBy('a.created_at')
            ->get(['d.id as due', 'd.period', 'a.meta', 'au.name as actor', 'a.created_at']);
        foreach ($logs as $l) {
            $this->line("  due #{$l->due} ({$l->period}) · {$l->meta} · por: ".($l->actor ?? 'sistema')." · {$l->created_at}");
        }
        if ($logs->isEmpty()) {
            $this->line('  (sin registros)');
        }

        return self::SUCCESS;
    }
}
