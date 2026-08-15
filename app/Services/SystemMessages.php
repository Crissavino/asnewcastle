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
