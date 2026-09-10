<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Segundo puesto: muchos juegan de dos cosas
            $table->string('position_secondary', 3)->nullable()->after('position');
            // Lesionado desde: null = sano. Timestamp para tener "desde cuándo" gratis
            $table->timestamp('injured_since')->nullable()->after('availability');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['position_secondary', 'injured_since']);
        });
    }
};
