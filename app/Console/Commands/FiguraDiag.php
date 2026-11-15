<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

/** Solo lectura: por qué el vestuario no muestra la votación de figura. */
class FiguraDiag extends Command
{
    protected $signature = 'app:figura-diag';

    protected $description = 'Foto del último partido y de las condiciones de la votación de figura';

    public function handle(): int
    {
        $event = Event::withoutGlobalScopes()
            ->where('kind', 'match')
            ->with('attendances')
            ->latest('starts_at')
            ->first();

        if (! $event) {
            $this->warn('No hay partidos.');

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'now' => now()->toIso8601String(),
            'tz' => config('app.timezone'),
            'event_id' => $event->id,
            'club_id' => $event->club_id,
            'opponent' => $event->opponent,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'kickoff_at' => $event->kickoff_at?->toIso8601String(),
            'cancelled_at' => $event->cancelled_at?->toIso8601String(),
            'mvp_opened_at' => $event->mvp_opened_at?->toIso8601String(),
            'mvp_closed_at' => $event->mvp_closed_at?->toIso8601String(),
            'attendance_confirmed_at' => $event->attendance_confirmed_at?->toIso8601String(),
            'is_finished' => $event->isFinished(),
            'poll_open' => $event->mvpPollOpen(),
            'attendances' => $event->attendances->count(),
            'status_in' => $event->attendances->where('status', 'in')->count(),
            'attended_true' => $event->attendances->where('attended', true)->count(),
            'present_ids' => $event->presentMemberIds()->count(),
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
