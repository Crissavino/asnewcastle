<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // Fixture (calendario oficial) scrapeado del /program de la liga,
            // igual que standings_json es el clasament. Solo los partidos del club.
            $table->string('fixture_url')->nullable()->after('standings_url');
            $table->json('fixture_json')->nullable()->after('fixture_url');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['fixture_url', 'fixture_json']);
        });
    }
};
