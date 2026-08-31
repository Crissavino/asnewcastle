<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\Notifications;
use App\Services\Push\Notifier;
use App\Services\WhatsApp\EventMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEventConvocation implements ShouldQueue
{
    use Queueable;

    /** $notice: new | update | cancel — cambia el encabezado del WhatsApp. */
    public function __construct(
        public Event $event,
        public bool $onlyUnanswered = false,
        public string $notice = 'new',
    ) {}

    public function handle(EventMessenger $messenger, Notifier $notifier, Notifications $inApp): void
    {
        // A un evento cancelado solo le corresponde el aviso de cancelación
        if ($this->event->isCancelled() && $this->notice !== 'cancel') {
            return;
        }

        // Recordatorio: solo a los que no definieron (sin contestar o en duda)
        $recipients = ($this->onlyUnanswered
            ? $this->event->membersToRemind()
            : $this->event->club->activeMembers())
            ->with('user')
            ->get();

        foreach ($recipients as $member) {
            $messenger->sendConvocation($this->event, $member, $this->notice);
        }

        // Push nativa, en paralelo al WhatsApp: "creo el evento → suena el teléfono"
        $notifier->event($this->event, $recipients, $this->notice, $this->onlyUnanswered);

        // Y la campanita in-app, a los mismos destinatarios (menos el autor)
        $inApp->event($this->event, $recipients, $this->notice, $this->onlyUnanswered);

        if ($this->notice === 'new') {
            $this->event->forceFill(
                $this->onlyUnanswered ? ['reminded_at' => now()] : ['notified_at' => now()],
            )->save();
        }
    }
}
