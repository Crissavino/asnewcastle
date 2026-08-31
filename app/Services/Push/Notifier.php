<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\Event;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * Traduce las notificaciones al idioma de cada jugador y las manda al push.
 * Reusa los diccionarios de lang/{locale}.json (las mismas claves que la web),
 * agrupando a los destinatarios por idioma para no traducir de más.
 */
class Notifier
{
    public function __construct(private PushSender $sender)
    {
    }

    /**
     * Push de un evento a una colección de members (con su user cargado).
     * $notice: new | update | cancel. $isReminder marca el recordatorio.
     */
    public function event(Event $event, Collection $recipients, string $notice, bool $isReminder = false): void
    {
        $isMatch = $event->isMatch();
        [$titleKey, $bodyKey] = $this->keysFor($notice, $isReminder, $isMatch);

        // Agrupar por idioma efectivo del jugador (es/ro; 'en' fallback → es)
        $byLocale = $recipients
            ->filter(fn ($m) => $m->user !== null)
            ->groupBy(fn ($m) => in_array($m->user->locale, ['es', 'ro'], true) ? $m->user->locale : 'es');

        foreach ($byLocale as $locale => $members) {
            $params = [
                'opponent' => $event->opponent,
                'date' => $event->starts_at->locale($locale)->isoFormat('ddd D MMM · HH:mm'),
            ];

            $title = __($titleKey, $params, $locale);
            $body = __($bodyKey, $params, $locale);

            $userIds = $members->pluck('user.id')->all();
            $tokens = DeviceToken::whereIn('user_id', $userIds)->pluck('token')->all();

            if (empty($tokens)) {
                continue;
            }

            $invalid = $this->sender->send($tokens, $title, $body, [
                'url' => '/agenda',
                'event_id' => (string) $event->id,
            ]);

            if ($invalid) {
                DeviceToken::whereIn('token', $invalid)->delete();
            }
        }
    }

    /**
     * Push de un mensaje del vestuario a los destinatarios ya filtrados (los que
     * corresponde avisar). Título por idioma; cuerpo = "Autor: texto" (o 📷).
     */
    public function vestuario(Message $message, Collection $recipients): void
    {
        // Texto genérico ("tenés mensajes sin leer"): la push llega una sola vez
        // por tanda sin leer y no se actualiza, así que no muestra un mensaje que
        // puede quedar viejo. Título y cuerpo en el idioma de cada jugador.
        $byLocale = $recipients
            ->filter(fn ($m) => $m->user !== null)
            ->groupBy(fn ($m) => in_array($m->user->locale, ['es', 'ro', 'en', 'ar'], true) ? $m->user->locale : 'en');

        foreach ($byLocale as $locale => $members) {
            $title = __('push.vestuario_title', [], $locale);
            $body = __('push.vestuario_body', [], $locale);

            $tokens = DeviceToken::whereIn('user_id', $members->pluck('user.id')->all())->pluck('token')->all();

            if (empty($tokens)) {
                continue;
            }

            $invalid = $this->sender->send($tokens, $title, $body, [
                'url' => '/vestuario',
                'message_id' => (string) $message->id,
            ]);

            if ($invalid) {
                DeviceToken::whereIn('token', $invalid)->delete();
            }
        }
    }

    /**
     * Push de recordatorio de cuota a los deudores, en el idioma de cada uno.
     *
     * @param  Collection<int, \App\Models\Member>  $recipients
     */
    public function dues(Collection $recipients): void
    {
        $byLocale = $recipients
            ->filter(fn ($m) => $m->user !== null)
            ->groupBy(fn ($m) => in_array($m->user->locale, ['es', 'ro', 'en'], true) ? $m->user->locale : 'es');

        foreach ($byLocale as $locale => $members) {
            $title = __('push.dues_title', [], $locale);
            $body = __('push.dues_body', [], $locale);

            $tokens = DeviceToken::whereIn('user_id', $members->pluck('user.id')->all())->pluck('token')->all();

            if (empty($tokens)) {
                continue;
            }

            $invalid = $this->sender->send($tokens, $title, $body, ['url' => '/cuota']);

            if ($invalid) {
                DeviceToken::whereIn('token', $invalid)->delete();
            }
        }
    }

    /** @return array{0: string, 1: string}  [titleKey, bodyKey] */
    private function keysFor(string $notice, bool $isReminder, bool $isMatch): array
    {
        $kind = $isMatch ? 'match' : 'training';

        if ($isReminder) {
            return ["push.reminder_{$kind}_title", "push.reminder_{$kind}_body"];
        }

        return ["push.{$notice}_{$kind}_title", "push.{$notice}_{$kind}_body"];
    }
}
