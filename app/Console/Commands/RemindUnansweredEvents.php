<?php

namespace App\Console\Commands;

use App\Jobs\SendEventConvocation;
use App\Models\Event;
use Illuminate\Console\Command;

class RemindUnansweredEvents extends Command
{
    protected $signature = 'eventos:recordar';

    protected $description = 'Recordatorio 24hs antes del evento a los que no contestaron o están en duda';

    public function handle(): int
    {
        $events = Event::query()
            ->whereNotNull('notified_at')
            ->whereNull('reminded_at')
            ->whereNull('cancelled_at')
            ->whereBetween('starts_at', [now(), now()->addDay()])
            ->get();

        foreach ($events as $event) {
            // Marcar primero: si el comando corre dos veces, no se duplica
            $event->forceFill(['reminded_at' => now()])->save();

            SendEventConvocation::dispatch($event, onlyUnanswered: true);
        }

        $this->info("Recordatorios despachados: {$events->count()}");

        return self::SUCCESS;
    }
}
