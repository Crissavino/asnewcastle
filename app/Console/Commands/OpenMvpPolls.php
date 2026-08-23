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

            // Sin al menos dos que hayan estado, no hay votación que valga
            $present = $event->presentMemberIds();

            if ($present->count() >= 2) {
                $system->mvpOpened($event);
                $inApp->mvpOpened($event, $present);
            }
        }

        $this->info("Votaciones abiertas: {$events->count()}");

        return self::SUCCESS;
    }
}
