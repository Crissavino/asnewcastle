<?php

namespace App\Jobs;

use App\Models\Member;
use App\Models\Message;
use App\Services\Push\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push de un mensaje del vestuario, pero SOLO al que corresponde:
 *  - no al autor,
 *  - no al que lo está mirando ahora (polleó hace poco),
 *  - y solo si estaba "al día" (no tenía mensajes sin leer). Así llega una push
 *    por el PRIMER mensaje sin leer, no una por cada mensaje.
 */
class NotifyVestuarioMessage implements ShouldQueue
{
    use Queueable;

    /** Margen para considerar que el jugador está mirando el vestuario (poll ~8s). */
    private const VIEWING_WINDOW = 15;

    public function __construct(public int $messageId) {}

    public function handle(Notifier $notifier): void
    {
        $message = Message::withoutGlobalScopes()->find($this->messageId);

        if (! $message || $message->is_system) {
            return;
        }

        $author = Member::withoutGlobalScopes()->find($message->member_id);

        if (! $author) {
            return;
        }

        $viewingCutoff = now()->subSeconds(self::VIEWING_WINDOW);
        $epoch = '1970-01-01 00:00:00';

        $recipients = Member::withoutGlobalScopes()
            ->where('club_id', $message->club_id)
            ->whereNull('left_at')
            ->where('id', '!=', $author->id)
            ->with('user')
            ->get();

        $toNotify = $recipients->filter(function (Member $m) use ($message, $viewingCutoff, $epoch) {
            // Lo está mirando ahora (poll reciente): no lo molestamos.
            if ($m->vestuario_read_at && $m->vestuario_read_at->gte($viewingCutoff)) {
                return false;
            }

            // ¿Ya tenía algo sin leer antes de este mensaje? Entonces ya se le avisó.
            $hadUnread = Message::withoutGlobalScopes()
                ->where('club_id', $message->club_id)
                ->where('is_system', false)
                ->where('member_id', '!=', $m->id)
                ->where('id', '<', $message->id)
                ->where('created_at', '>', $m->vestuario_read_at ?? $epoch)
                ->exists();

            return ! $hadUnread; // solo si estaba al día
        });

        if ($toNotify->isNotEmpty()) {
            $notifier->vestuario($message, $toNotify);
        }
    }
}
