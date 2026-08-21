<?php

namespace App\Jobs;

use App\Models\Event;
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
    ) {
    }

    public function handle(EventMessenger $messenger, Notifier $notifier): void
    {
        // A un evento cancelado solo le corresponde el aviso de cancelación
        if ($this->event->isCancelled() && $this->notice !== 'cancel') {
            return;
        }

        $answered = $this->event->attendances()->pluck('member_id');

        $recipients = $this->event->club->activeMembers()
            ->with('user')
            ->when($this->onlyUnanswered, fn ($q) => $q->whereNotIn('id', $answered))
            ->get();

        foreach ($recipients as $member) {
            $messenger->sendConvocation($this->event, $member, $this->notice);
        }

        // Push nativa, en paralelo al WhatsApp: "creo el evento → suena el teléfono"
        $notifier->event($this->event, $recipients, $this->notice, $this->onlyUnanswered);

        if ($this->notice === 'new') {
            $this->event->forceFill(
                $this->onlyUnanswered ? ['reminded_at' => now()] : ['notified_at' => now()],
            )->save();
        }
    }
}
