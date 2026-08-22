<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            // Aislamiento por club, como todo (global scope BelongsToClub)
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            // El destinatario: la notificación es POR jugador (no del club).
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // event | dues | mvp | attendance | payment
            // Texto guardado como {key, params} i18n, igual que los mensajes de
            // sistema del vestuario: cada cliente lo arma en su idioma con t().
            $table->string('body_key');
            $table->json('body_params')->nullable();
            $table->string('url')->default('/agenda'); // adónde lleva al tocarla
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // El contador de no-leídas por jugador es la query caliente
            $table->index(['member_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
