<?php

namespace App\Services\Translation;

/**
 * Driver de traducción que no llama a ninguna API: devuelve el texto sin
 * tocar. Es el default en dev/tests para no gastar cuota ni salir a la red.
 */
class NullTranslator implements Translator
{
    public function translate(string $text, string $to): array
    {
        return [
            'text' => $text,
            'from' => LocaleGuesser::guess($text),
        ];
    }
}
