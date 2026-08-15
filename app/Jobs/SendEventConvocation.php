<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\WhatsApp\EventMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEventConvocation implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public bool $onlyUnanswered = false,
    ) {
    }

    public function handle(EventMessenger $messenger): void
    {
        $answered = $this->event->attendances()->pluck('member_id');

        $recipients = $this->event->club->activeMembers()
            ->with('user')
            ->when($this->onlyUnanswered, fn ($q) => $q->whereNotIn('id', $answered))
            ->get();

        foreach ($recipients as $member) {
            $messenger->sendConvocation($this->event, $member);
        }

        $this->event->forceFill(
            $this->onlyUnanswered ? ['reminded_at' => now()] : ['notified_at' => now()],
        )->save();
    }
}
