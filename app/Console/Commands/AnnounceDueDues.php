<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Due;
use App\Models\Message;
use App\Services\Notifications;
use Illuminate\Console\Command;

class AnnounceDueDues extends Command
{
    protected $signature = 'cuotas:avisar';

    protected $description = 'Recuerda la cuota a los que deben (días 1/3/5/7/10/15/20/25/30). El día 10 (vencimiento) además publica en el vestuario quién pagó y quién debe.';

    /** Días del mes en que se recuerda a los deudores. El 10 es el vencimiento. */
    public const REMINDER_DAYS = [1, 3, 5, 7, 10, 15, 20, 25, 30];

    public const DUE_DAY = 10;

    public function handle(Notifications $inApp): int
    {
        $today = today();

        if (! in_array($today->day, self::REMINDER_DAYS, true)) {
            $this->info('Hoy no toca recordar cuotas.');

            return self::SUCCESS;
        }

        $period = $today->copy()->startOfMonth()->toDateString();
        $isDueDay = $today->day === self::DUE_DAY;
        $reminded = 0;

        Club::query()->each(function (Club $club) use ($inApp, $period, $isDueDay, &$reminded) {
            $dues = Due::withoutGlobalScopes()
                ->where('club_id', $club->id)
                ->whereDate('period', $period)
                ->with('member.user:id,name')
                ->get();

            if ($dues->isEmpty()) {
                return;
            }

            $pending = $dues->where('status', 'pending');

            // Recordatorio personal (campanita + push) a cada deudor.
            foreach ($pending as $due) {
                $inApp->dueDue($due);
                $reminded++;
            }

            // El día del vencimiento: además, anuncio en el vestuario.
            if (! $isDueDay) {
                return;
            }

            if ($pending->isEmpty()) {
                Message::create([
                    'club_id' => $club->id,
                    'is_system' => true,
                    'body' => json_encode([
                        'key' => 'system.dues_all_paid',
                        'params' => ['period' => $period],
                    ]),
                ]);

                return;
            }

            $names = $pending
                ->map(fn ($d) => strtok($d->member->user->name ?? '', ' ') ?: $d->member->user->name)
                ->filter()
                ->implode(', ');

            Message::create([
                'club_id' => $club->id,
                'is_system' => true,
                'body' => json_encode([
                    'key' => 'system.dues_due',
                    'params' => [
                        'period' => $period,
                        'paid' => $dues->where('status', 'paid')->count(),
                        'total' => $dues->count(),
                        'names' => $names,
                    ],
                ]),
            ]);
        });

        $this->info("Recordatorios de cuota enviados: {$reminded}.");

        return self::SUCCESS;
    }
}
