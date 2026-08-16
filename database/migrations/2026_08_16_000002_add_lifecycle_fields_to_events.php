<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('reminded_at');
            $table->unsignedTinyInteger('goals_for')->nullable()->after('cancelled_at');
            $table->unsignedTinyInteger('goals_against')->nullable()->after('goals_for');
            // Cuándo se cerró (y anunció) la votación de figura
            $table->timestamp('mvp_closed_at')->nullable()->after('mvp_opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'goals_for', 'goals_against', 'mvp_closed_at']);
        });
    }
};
