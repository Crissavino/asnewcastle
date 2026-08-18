<?php

namespace App\Services\Translation;

/**
 * Detección local (sin API) del idioma de un mensaje corto de chat, acotada a
 * los dos idiomas del club: rumano (ro) y español (es). Alcanza para decidir
 * si mostrar el link de "traducir" sin gastar cuota. La API de Azure, cuando
 * se traduce de verdad, devuelve la detección precisa y ahí se corrige.
 */
class LocaleGuesser
{
    /** Letras que solo existen en rumano. */
    private const RO_LETTERS = '/[ăâîșțĂÂÎȘȚ]/u';

    /** Palabras muy comunes, exclusivas de cada idioma. */
    private const RO_WORDS = ['si', 'sunt', 'este', 'meci', 'vine', 'baieti', 'haideti', 'multumesc', 'unde', 'cine', 'astazi', 'maine'];

    private const ES_WORDS = ['que', 'esta', 'vamos', 'partido', 'gracias', 'porque', 'como', 'donde', 'manana', 'hoy', 'nadie', 'pibe'];

    public static function guess(string $text): string
    {
        // Señal fuerte: letras rumanas → seguro rumano.
        if (preg_match(self::RO_LETTERS, $text)) {
            return 'ro';
        }

        $words = preg_split('/\s+/', mb_strtolower(trim($text))) ?: [];
        $ro = count(array_intersect($words, self::RO_WORDS));
        $es = count(array_intersect($words, self::ES_WORDS));

        if ($ro > $es) {
            return 'ro';
        }

        // Empate o mayoría español → español (es el idioma mayoritario del club).
        return 'es';
    }
}
