<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // Resultados de temporadas pasadas, importados una vez desde el
            // /program de la AJF (comando tabla:historial). Solo para el
            // historial contra cada rival del pronóstico.
            $table->json('history_json')->nullable()->after('fixture_json');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('history_json');
        });
    }
};
