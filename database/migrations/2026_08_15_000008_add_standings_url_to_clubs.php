<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // Página de clasament de la liga (frf-ajf.ro) de donde se scrapea la tabla
            $table->string('standings_url')->nullable()->after('standings_json');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('standings_url');
        });
    }
};
