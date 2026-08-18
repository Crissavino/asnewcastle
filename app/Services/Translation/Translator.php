<?php

namespace App\Services\Translation;

interface Translator
{
    /**
     * Traduce $text al idioma $to (es|ro). Devuelve el texto traducido y el
     * idioma de origen detectado.
     *
     * @return array{text: string, from: ?string}
     *
     * @throws \RuntimeException si la API falla o se acabó la cuota.
     */
    public function translate(string $text, string $to): array;
}
