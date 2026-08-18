<?php

use App\Models\Message;
use App\Services\Translation\LocaleGuesser;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Rellena el idioma de los mensajes de jugadores que existían antes de la
     * función de traducción (quedaron con detected_locale null y por eso les
     * aparecía el link "traducir" aunque ya estuvieran en el idioma del lector).
     */
    public function up(): void
    {
        Message::withoutGlobalScopes()
            ->where('is_system', false)
            ->whereNull('detected_locale')
            ->get()
            ->each(function (Message $m) {
                if (trim((string) $m->body) !== '') {
                    $m->updateQuietly(['detected_locale' => LocaleGuesser::guess($m->body)]);
                }
            });
    }

    public function down(): void
    {
        // No se revierte: es solo relleno de datos.
    }
};
