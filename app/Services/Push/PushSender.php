<?php

namespace App\Services\Push;

interface PushSender
{
    /**
     * Manda una push a una lista de tokens de dispositivo. Mismo título/cuerpo
     * para todos (ya vienen agrupados por idioma desde el Notifier). `data`
     * son pares clave/valor que la app usa para abrir la pantalla correcta.
     *
     * Devuelve los tokens que el proveedor reportó como inválidos (para
     * borrarlos). Nunca lanza por un token roto: si el proveedor falla del
     * todo, se traga el error — una push perdida no rompe la app.
     *
     * @param  string[]  $tokens
     * @param  array<string, string>  $data
     * @return string[]  tokens inválidos a eliminar
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array;
}
