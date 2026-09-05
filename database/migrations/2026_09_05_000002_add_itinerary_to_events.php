<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Itinerario del partido: starts_at pasa a ser la hora de ENCUENTRO,
            // kickoff_at es la hora del partido y venue_url el link de la cancha
            // (Maps). Antes vivían pegados en las notas como texto libre.
            $table->dateTime('kickoff_at')->nullable()->after('starts_at');
            $table->string('venue_url')->nullable()->after('venue');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['kickoff_at', 'venue_url']);
        });
    }
};
