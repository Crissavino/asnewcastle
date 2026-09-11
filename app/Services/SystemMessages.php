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

    /**
     * Goleadores y asistencias del partido, cuando el manager cargó el detalle.
     * Se publica una sola vez, cuando se juntan presentes confirmados y
     * resultado (los llama PresenceController y AgendaController::result).
     */
    public function matchSummary(Event $event): void
    {
        $rows = $event->attendances()
            ->where('attended', true)
            ->with('member.user:id,name')
            ->get();

        $names = fn (string $field) => $rows
            ->filter(fn ($a) => $a->{$field} > 0)
            ->sortByDesc($field)
            ->map(function ($a) use ($field) {
                $first = strtok($a->member->user->name ?? '', ' ') ?: $a->member->user->name;

                return $a->{$field} > 1 ? "{$first} x{$a->{$field}}" : $first;
            })
            ->implode(', ');

        $goals = $names('goals');
        $assists = $names('assists');

        if ($goals === '') {
            return;
        }

        Message::create([
            'club_id' => $event->club_id,
            'is_system' => true,
            'body' => json_encode([
                'key' => $assists !== '' ? 'system.match_summary_full' : 'system.match_summary',
                'params' => array_filter([
                    'opponent' => $event->opponent,
                    'goals' => $goals,
                    'assists' => $assists,
                ]),
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
