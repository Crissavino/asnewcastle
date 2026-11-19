<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Solo lectura: quién debe cuota y qué meses debe, a hoy.
 * Una línea por jugador con los períodos pendientes y el total.
 */
class Deudores extends Command
{
    protected $signature = 'app:deudores';

    protected $description = 'Deudores de cuota y los meses que deben (solo lectura)';

    public function handle(): int
    {
        $rows = DB::table('dues as d')
            ->join('members as m', 'm.id', '=', 'd.member_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('d.status', 'pending')
            ->orderBy('u.name')
            ->orderBy('d.period')
            ->get(['u.name as jugador', 'm.shirt_number as dorsal', 'm.left_at', 'd.period', 'd.amount_cents', 'd.due_date']);

        $this->info('DEUDORES al '.now()->toDateString().' (status=pending)');
        $this->newLine();

        if ($rows->isEmpty()) {
            $this->line('  no hay cuotas pendientes');

            return self::SUCCESS;
        }

        $total = 0;
        $byPlayer = $rows->groupBy('jugador');

        foreach ($byPlayer as $jugador => $dues) {
            $sum = $dues->sum('amount_cents');
            $total += $sum;
            $meses = $dues->map(fn ($d) => substr($d->period, 0, 7).' ('.number_format($d->amount_cents / 100, 2).')')->implode(' | ');
            $baja = $dues->first()->left_at ? ' [BAJA]' : '';
            $dorsal = $dues->first()->dorsal;
            $this->line("  {$dorsal} {$jugador}{$baja} · ".count($dues)." mes(es) · TOTAL ".number_format($sum / 100, 2)." :: {$meses}");
        }

        $this->newLine();
        $this->info('JUGADORES CON DEUDA: '.$byPlayer->count().' · CUOTAS PENDIENTES: '.$rows->count().' · TOTAL '.number_format($total / 100, 2));

        return self::SUCCESS;
    }
}
