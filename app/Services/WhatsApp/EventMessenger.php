<?php

namespace App\Services\WhatsApp;

use App\Models\Event;
use App\Models\Member;
use Illuminate\Support\Carbon;

/**
 * Arma y manda la convocatoria de un evento por WhatsApp.
 * La plantilla (categoría utility, con botones Voy / Duda / No voy)
 * lleva: 1 título, 2 fecha y hora, 3 cancha, 4 casaca, y 5-7 los
 * payloads de los botones (att:{event}:{in|maybe|out}).
 */
class EventMessenger
{
    public function __construct(protected WhatsAppChannel $channel)
    {
    }

    /** $notice: new (convocatoria) | update (cambió) | cancel (se canceló) */
    public function sendConvocation(Event $event, Member $member, string $notice = 'new'): void
    {
        $user = $member->user;
        $locale = $user->locale ?? config('app.fallback_locale');

        $titleKey = match ($notice) {
            'update' => $event->isMatch() ? 'wa.updated_match' : 'wa.updated_training',
            'cancel' => $event->isMatch() ? 'wa.cancelled_match' : 'wa.cancelled_training',
            default => $event->isMatch() ? 'wa.match_title' : 'wa.training_title',
        };

        $title = trans($titleKey, ['opponent' => $event->opponent], $locale);

        $when = Carbon::parse($event->starts_at)
            ->locale($locale)
            ->translatedFormat('l j F · H:i');

        $kit = ! $event->isMatch() ? '—' : trans(match ($event->kit) {
            'away' => 'wa.kit_away',
            'both' => 'wa.kit_both',
            default => 'wa.kit_home',
        }, [], $locale);

        $this->channel->sendTemplate($user->phone, config('services.twilio.event_template_sid') ?? 'event', [
            '1' => $title,
            '2' => $when,
            '3' => $event->venue,
            '4' => $kit,
            '5' => "att:{$event->id}:in",
            '6' => "att:{$event->id}:maybe",
            '7' => "att:{$event->id}:out",
        ]);
    }

    /** Respuesta de cortesía dentro de la ventana de 24hs de la conversación. */
    public function sendReplyAck(Member $member, string $status, int $confirmedCount): void
    {
        $user = $member->user;
        $locale = $user->locale ?? config('app.fallback_locale');
        $name = strtok($user->name ?? '', ' ') ?: $user->name;

        $body = match ($status) {
            'in' => trans('wa.reply_in', ['name' => $name, 'count' => $confirmedCount], $locale),
            'maybe' => trans('wa.reply_maybe', ['name' => $name], $locale),
            default => trans('wa.reply_out', ['name' => $name], $locale),
        };

        $this->channel->sendText($user->phone, $body);
    }
}
