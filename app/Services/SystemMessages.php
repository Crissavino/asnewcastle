<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Member;
use App\Models\Message;

/**
 * Mensajes automáticos del vestuario. El body guarda {key, params}
 * y cada cliente lo traduce a su idioma con el diccionario compartido.
 */
class SystemMessages
{
    public function eventCreated(Event $event): void
    {
        Message::create([
            'club_id' => $event->club_id,
            'is_system' => true,
            'body' => json_encode([
                'key' => $event->isMatch() ? 'system.event_created_match' : 'system.event_created_training',
                'params' => array_filter([
                    'opponent' => $event->opponent,
                    'date' => $event->starts_at->toIso8601String(),
                ]),
            ]),
        ]);
    }

    public function eventUpdated(Event $event): void
    {
        $this->announce($event, $event->isMatch() ? 'system.event_updated_match' : 'system.event_updated_training');
    }

    public function eventCancelled(Event $event): void
    {
        $this->announce($event, $event->isMatch() ? 'system.event_cancelled_match' : 'system.event_cancelled_training');
    }

    public function result(Event $event): void
    {
        Message::create([
            'club_id' => $event->club_id,
            'is_system' => true,
            'body' => json_encode([
                'key' => 'system.result',
                'params' => [
                    'opponent' => $event->opponent,
                    'gf' => $event->goals_for,
                    'ga' => $event->goals_against,
                ],
            ]),
        ]);
    }

    public function mvpWinner(Event $event, string $names, int $votes): void
    {
        Message::create([
            'club_id' => $event->club_id,
            'is_system' => true,
            'body' => json_encode([
                'key' => 'system.mvp_winner',
                'params' => ['opponent' => $event->opponent, 'name' => $names, 'votes' => $votes],
            ]),
        ]);
    }

    protected function announce(Event $event, string $key): void
    {
        Message::create([
            'club_id' => $event->club_id,
            'is_system' => true,
            'body' => json_encode([
                'key' => $key,
                'params' => array_filter([
                    'opponent' => $event->opponent,
                    'date' => $event->starts_at->toIso8601String(),
                ]),
            ]),
        ]);
    }

    public function mvpOpened(Event $event): void
    {
        Message::create([
            'club_id' => $event->club_id,
            'is_system' => true,
            'body' => json_encode([
                'key' => 'system.mvp_open',
                'params' => array_filter(['opponent' => $event->opponent]),
            ]),
        ]);
    }

    public function confirmed(Member $member, Event $event): void
    {
        Message::create([
            'club_id' => $event->club_id,
            'is_system' => true,
            'body' => json_encode([
                'key' => $event->isMatch() ? 'system.confirmed_match' : 'system.confirmed_training',
                'params' => array_filter([
                    'name' => strtok($member->user->name ?? '', ' ') ?: $member->user->name,
                    'opponent' => $event->opponent,
                ]),
            ]),
        ]);
    }
}
