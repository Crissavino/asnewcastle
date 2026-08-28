<?php

namespace App\Services\Translation;

/**
 * Detección local (sin API) del idioma de un mensaje corto de chat. Alcanza
 * para decidir si mostrar el link de "traducir" sin gastar cuota; la API de
 * Azure, cuando se traduce de verdad, devuelve la detección precisa y corrige.
 *
 * Reconoce los idiomas del plantel (rumano, español, inglés y árabe). Si no
 * hay ninguna señal clara (holandés, francés, etc.), devuelve UNKNOWN en vez
 * de asumir un idioma: así el front igual ofrece traducir y ningún mensaje
 * extranjero queda sin el botón por una mala detección.
 */
class LocaleGuesser
{
    /** Idioma indeterminado: el front ofrece "traducir" igual. */
    public const UNKNOWN = 'und';

    /** Letras que solo existen en rumano. */
    private const RO_LETTERS = '/[ăâîșțĂÂÎȘȚ]/u';

    /** Palabras muy comunes, exclusivas de cada idioma. */
    private const RO_WORDS = ['si', 'sunt', 'este', 'meci', 'vine', 'baieti', 'haideti', 'multumesc', 'unde', 'cine', 'astazi', 'maine'];

    private const ES_WORDS = ['que', 'esta', 'vamos', 'partido', 'gracias', 'porque', 'como', 'donde', 'manana', 'hoy', 'nadie', 'pibe'];

    private const EN_WORDS = ['the', 'and', 'match', 'training', 'guys', 'today', 'tomorrow', 'thanks', 'who', 'where', 'going', 'see', 'game'];

    public static function guess(string $text): string
    {
        // Árabe: por rango unicode, señal inequívoca.
        if (preg_match('/\p{Arabic}/u', $text)) {
            return 'ar';
        }

        // Señal fuerte: letras rumanas → seguro rumano.
        if (preg_match(self::RO_LETTERS, $text)) {
            return 'ro';
        }

        $words = preg_split('/\s+/', mb_strtolower(trim($text))) ?: [];
        $scores = [
            'ro' => count(array_intersect($words, self::RO_WORDS)),
            'es' => count(array_intersect($words, self::ES_WORDS)),
            'en' => count(array_intersect($words, self::EN_WORDS)),
        ];

        $best = max($scores);

        // Sin ninguna coincidencia: idioma desconocido. No asumir español.
        if ($best === 0) {
            return self::UNKNOWN;
        }

        // El de más coincidencias; empate resuelto por orden (ro, es, en).
        return array_key_first(array_filter($scores, fn ($n) => $n === $best));
    }
}
