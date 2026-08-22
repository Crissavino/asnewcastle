<?php

namespace App\Services;

use App\Models\Due;
use App\Models\Event;
use App\Models\Member;
use App\Models\Notification;
use Illuminate\Support\Collection;

/**
 * Notificaciones in-app (la campanita). Al revés que SystemMessages —que
 * publica UN mensaje al club— acá hacemos fan-out: una fila por jugador
 * destinatario, con {key, params} i18n. Se llama desde jobs, comandos y
 * webhooks (sin club activo), por eso el club_id siempre va explícito.
 */
class Notifications
{
    /**
     * Inserta una notificación por cada member_id. Una sola query.
     *
     * @param  iterable<int>  $memberIds
     */
    public function deliver(int $clubId, iterable $memberIds, string $type, string $key, array $params = [], string $url = '/agenda'): void
    {
        $now = now();
        $rows = [];

        foreach ($memberIds as $memberId) {
            $rows[] = [
                'club_id' => $clubId,
                'member_id' => $memberId,
                'type' => $type,
                'body_key' => $key,
                // objeto para que el cliente siempre reciba {} y no []
                'body_params' => json_encode((object) $params),
                'url' => $url,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            Notification::insert($rows);
        }
    }

    /**
     * Evento nuevo / cambio / cancelación / recordatorio. El autor no se
     * autonotifica. $notice: new | update | cancel.
     *
     * @param  Collection<int, Member>  $recipients
     */
    public function event(Event $event, Collection $recipients, string $notice, bool $isReminder = false): void
    {
        $kind = $event->isMatch() ? 'match' : 'training';
        $key = $isReminder
            ? "notifications.event_reminder_{$kind}"
            : "notifications.event_{$notice}_{$kind}";

        $ids = $recipients->pluck('id')
            ->reject(fn ($id) => $id === $event->created_by_member_id)
            ->values();

        $this->deliver($event->club_id, $ids, 'event', $key, array_filter([
            'opponent' => $event->opponent,
            'date' => $event->starts_at->toIso8601String(),
        ]), '/agenda');
    }

    /** Se generó la cuota del mes del jugador. */
    public function dueGenerated(Due $due): void
    {
        $this->deliver($due->club_id, [$due->member_id], 'dues', 'notifications.dues_generated', [
            'period' => $due->period->toDateString(),
        ], '/cuota');
    }

    /** Día del vencimiento con la cuota impaga. */
    public function dueDue(Due $due): void
    {
        $this->deliver($due->club_id, [$due->member_id], 'dues', 'notifications.dues_due', [
            'period' => $due->period->toDateString(),
        ], '/cuota');
    }

    /**
     * Se abrió la votación de la figura, a los que jugaron.
     *
     * @param  Collection<int, int>  $attendeeMemberIds
     */
    public function mvpOpened(Event $event, Collection $attendeeMemberIds): void
    {
        $this->deliver($event->club_id, $attendeeMemberIds, 'mvp', 'notifications.mvp_open',
            array_filter(['opponent' => $event->opponent]), '/vestuario');
    }

    /**
     * Salió la figura del partido, a los que jugaron.
     *
     * @param  Collection<int, int>  $attendeeMemberIds
     */
    public function mvpWinner(Event $event, Collection $attendeeMemberIds, string $names): void
    {
        $this->deliver($event->club_id, $attendeeMemberIds, 'mvp', 'notifications.mvp_winner', [
            'opponent' => $event->opponent,
            'name' => $names,
        ], '/vestuario');
    }

    /**
     * Extra del manager: alguien respondió Voy/No voy a un evento. 'maybe' no
     * avisa (poco accionable). El que respondió no se autonotifica.
     */
    public function attendanceResponded(Member $actor, Event $event, string $status): void
    {
        if (! in_array($status, ['in', 'out'], true)) {
            return;
        }

        $managerIds = $event->club->activeMembers()
            ->where('role', 'manager')
            ->where('members.id', '!=', $actor->id)
            ->pluck('id');

        // Sin :opponent: los entrenamientos no tienen rival, y el texto igual
        // se entiende ("Fulano confirmó que va").
        $this->deliver($event->club_id, $managerIds, 'attendance', "notifications.attendance_{$status}",
            array_filter(['name' => $this->firstName($actor)]), '/agenda');
    }

    /** Extra del manager: entró un pago de cuota. */
    public function paymentReceived(Due $due): void
    {
        $due->loadMissing('member.user');

        $managerIds = Member::query()
            ->where('club_id', $due->club_id)
            ->whereNull('left_at')
            ->where('role', 'manager')
            ->pluck('id');

        $this->deliver($due->club_id, $managerIds, 'payment', 'notifications.payment',
            array_filter(['name' => $this->firstName($due->member)]), '/cuota');
    }

    /** Solo el nombre de pila, como en SystemMessages. */
    private function firstName(?Member $member): string
    {
        $name = $member?->user?->name ?? '';

        return strtok($name, ' ') ?: $name;
    }
}
