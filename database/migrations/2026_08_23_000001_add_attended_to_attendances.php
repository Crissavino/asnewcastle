<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Lo que dijo (status) y lo que pasó (attended) son cosas distintas.
            // null = el manager todavía no confirmó presentes para ese evento.
            $table->boolean('attended')->nullable()->after('status');
            // Nullables: el manager puede marcar presente a alguien que nunca
            // contestó la convocatoria (fila sin status ni responded_at).
            $table->string('status', 6)->nullable()->change();
            $table->timestamp('responded_at')->nullable()->change();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('attendance_confirmed_at')->nullable()->after('mvp_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('attended');
            $table->string('status', 6)->nullable(false)->change();
            $table->timestamp('responded_at')->nullable(false)->change();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('attendance_confirmed_at');
        });
    }
};
