<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // normal (cuota del club) | becado (no paga) | custom (monto propio).
            // Solo el manager ve y edita esto: nunca se expone a otros jugadores.
            $table->string('fee_type', 8)->default('normal')->after('availability');
            $table->unsignedInteger('custom_fee_cents')->nullable()->after('fee_type');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['fee_type', 'custom_fee_cents']);
        });
    }
};
