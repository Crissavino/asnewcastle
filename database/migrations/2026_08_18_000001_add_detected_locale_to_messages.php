<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Idioma detectado del mensaje del jugador (es|ro). Se usa para
            // decidir si mostrar el link de traducir y como origen de la API.
            $table->string('detected_locale', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('detected_locale');
        });
    }
};
