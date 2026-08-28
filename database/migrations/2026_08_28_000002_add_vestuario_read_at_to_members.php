<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Último leído" del vestuario por jugador: sirve para pushear solo el PRIMER
 * mensaje sin leer (no uno por mensaje) y para no pushear a quien lo está mirando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->timestamp('vestuario_read_at')->nullable()->after('subscription_status');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('vestuario_read_at');
        });
    }
};
