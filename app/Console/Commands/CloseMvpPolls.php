<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Notifications;
use App\Services\SystemMessages;
use Illuminate\Console\Command;

class CloseMvpPolls extends Command
{
    protected $signature = 'figura:cerrar';

    protected $description = 'Cierra las votaciones de figura vencidas y anuncia al ganador en el vestuario';

    public function handle(SystemMessages $system, Notifications $inApp): int
    {
        $events = Event::query()
            ->where('kind', 'match')
            ->whereNotNull('mvp_opened_at')
            ->whereNull('mvp_closed_at')
            ->where('starts_at', '<', now()->subHours(50))
            ->with('mvpVotes.voted.user', 'attendances')
            ->get();

        foreach ($events as $event) {
            // Marcar primero: si el comando corre dos veces, no se anuncia doble
            $event->forceFill(['mvp_closed_at' => now()])->save();

            if ($event->mvpVotes->isEmpty()) {
                continue;
            }

            $counts = $event->mvpVotes->countBy('voted_member_id');
            $max = $counts->max();

            // Empate: se anuncian todos los que llegaron al máximo
            $names = $counts->filter(fn ($v) => $v === $max)
                ->keys()
                ->map(fn ($memberId) => $event->mvpVotes
                    ->firstWhere('voted_member_id', $memberId)?->voted?->user?->name)
                ->filter()
                ->implode(' & ');

            $system->mvpWinner($event, $names, $max);
            $inApp->mvpWinner($event, $event->presentMemberIds(), $names);
        }

        $this->info("Votaciones cerradas: {$events->count()}");

        return self::SUCCESS;
    }
}
