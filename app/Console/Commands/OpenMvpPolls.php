<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Notifications;
use App\Services\SystemMessages;
use Illuminate\Console\Command;

class OpenMvpPolls extends Command
{
    protected $signature = 'figura:abrir';

    protected $description = 'Abre la votación de figura para los partidos que terminaron';

    public function handle(SystemMessages $system, Notifications $inApp): int
    {
        $events = Event::query()
            ->where('kind', 'match')
            ->whereNull('mvp_opened_at')
            ->where('starts_at', '<', now()->subHours(2))
            ->where('starts_at', '>', now()->subDay())
            ->with('attendances')
            ->get();

        foreach ($events as $event) {
            // Marcar siempre, para no reevaluar el mismo partido cada hora
            $event->forceFill(['mvp_opened_at' => now()])->save();

            // Sin al menos dos que hayan ido, no hay votación que valga
            $attended = $event->attendances->where('status', 'in');

            if ($attended->count() >= 2) {
                $system->mvpOpened($event);
                $inApp->mvpOpened($event, $attended->pluck('member_id'));
            }
        }

        $this->info("Votaciones abiertas: {$events->count()}");

        return self::SUCCESS;
    }
}
