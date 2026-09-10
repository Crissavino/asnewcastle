<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Por qué no va: work | injury | travel | other. Opcional, lo elige el jugador
            $table->string('absence_reason', 10)->nullable()->after('status');
            // Qué pasó en cancha, lo carga el manager: starter | sub | bench
            $table->string('participation', 10)->nullable()->after('attended');
            $table->unsignedTinyInteger('goals')->default(0)->after('participation');
            $table->unsignedTinyInteger('assists')->default(0)->after('goals');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['absence_reason', 'participation', 'goals', 'assists']);
        });
    }
};
