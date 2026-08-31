<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Notifications;
use App\Services\Push\Notifier;
use App\Services\WhatsApp\EventMessenger;
use Illuminate\Console\Command;

/**
 * One-off: manda el recordatorio SOLO a los que están "en duda" de un evento.
 * Existe porque el 31.08.2026 el botón "Recordar" salió cuando todavía excluía
 * a los "duda"; desde entonces el botón ya los incluye, así que esto sirve
 * para emparejar sin repetirle el aviso a los que no contestaron.
 *
 *   php artisan eventos:recordar-duda {modo} {--event=}
 *   modo=go envía; cualquier otra cosa es simulacro. Sin --event toma el próximo.
 */
class RecordarEnDuda extends Command
{
    protected $signature = 'eventos:recordar-duda {modo=dry} {--event= : ID del evento; por defecto el próximo}';

    protected $description = 'Recordatorio solo a los que están en duda de un evento';

    public function handle(EventMessenger $messenger, Notifier $notifier, Notifications $inApp): int
    {
        $go = $this->argument('modo') === 'go';
        $this->warn($go ? '>>> MODO GO: se envía de verdad' : '>>> SIMULACRO (dry-run). Pasá "go" para enviar.');

        $upcoming = Event::query()
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        foreach ($upcoming as $e) {
            $this->line("  #{$e->id} · {$e->kind}".($e->opponent ? " vs {$e->opponent}" : '')." · {$e->starts_at} · en duda: ".$this->maybes($e)->count());
        }

        $event = $this->option('event') ? $upcoming->firstWhere('id', (int) $this->option('event')) : $upcoming->first();

        if (! $event) {
            $this->error('No hay evento próximo (o el --event no existe / ya pasó).');

            return self::FAILURE;
        }

        $recipients = $this->maybes($event);

        $this->newLine();
        $this->info("Evento #{$event->id} → en duda ({$recipients->count()}): "
            .$recipients->map(fn ($m) => trim($m->shirt_number.' '.$m->user->name))->implode(' · '));

        if (! $go) {
            $this->info('Fin del simulacro.');

            return self::SUCCESS;
        }

        // Exactamente lo mismo que manda SendEventConvocation con onlyUnanswered
        foreach ($recipients as $member) {
            $messenger->sendConvocation($event, $member);
        }
        $notifier->event($event, $recipients, 'new', true);
        $inApp->event($event, $recipients, 'new', true);

        $this->info("LISTO. Recordatorio enviado a {$recipients->count()}.");

        return self::SUCCESS;
    }

    private function maybes(Event $event)
    {
        return $event->club->activeMembers()
            ->with('user')
            ->whereIn('members.id', $event->attendances()->where('status', 'maybe')->select('member_id'))
            ->get();
    }
}
