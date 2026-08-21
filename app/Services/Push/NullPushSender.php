<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * Driver de push que no manda nada: registra en el log. Es el default en
 * dev/tests para no depender de Firebase ni salir a la red.
 */
class NullPushSender implements PushSender
{
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        Log::info('[push:null] '.$title.' — '.$body.' → '.count($tokens).' tokens');

        return []; // ninguno inválido
    }
}
