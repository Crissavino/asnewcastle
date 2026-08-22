<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Due;
use App\Services\Notifications;
use Illuminate\Console\Command;

class GenerateMonthlyDues extends Command
{
    protected $signature = 'cuotas:generar {--period= : Primer día del mes, ej 2026-09-01}';

    protected $description = 'Genera las cuotas del período para los miembros activos de cada club';

    public function handle(Notifications $inApp): int
    {
        $period = $this->option('period')
            ? now()->parse($this->option('period'))->startOfMonth()
            : now()->startOfMonth();

        $created = 0;

        Club::query()->where('monthly_fee_cents', '>', 0)->each(function (Club $club) use ($period, $inApp, &$created) {
            foreach ($club->activeMembers()->get() as $member) {
                // Becados no generan cuota; custom usa su monto propio
                $amount = $member->monthlyFeeCents();

                if ($amount === null || $amount === 0) {
                    continue;
                }

                $due = Due::withoutGlobalScopes()->firstOrCreate(
                    [
                        'club_id' => $club->id,
                        'member_id' => $member->id,
                        'period' => $period,
                    ],
                    [
                        'amount_cents' => $amount,
                        'status' => 'pending',
                        'due_date' => $period->copy()->day(20)->toDateString(),
                    ],
                );

                if ($due->wasRecentlyCreated) {
                    $inApp->dueGenerated($due);
                }

                $created += (int) $due->wasRecentlyCreated;
            }
        });

        $this->info("Cuotas nuevas del período {$period->toDateString()}: {$created}");

        return self::SUCCESS;
    }
}
