<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Formulario público de legitimación: el que entra por el link firmado
// todavía no tiene usuario ni member — la ficha queda sin member y el
// delegado la ve igual en su tabla (el ZIP sale con el nombre cargado).
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite no soporta dropForeign; el rebuild de change() alcanza
            Schema::table('registrations', function (Blueprint $table) {
                $table->foreignId('member_id')->nullable()->change();
            });

            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->change();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('registrations', function (Blueprint $table) {
                $table->foreignId('member_id')->nullable(false)->change();
            });

            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable(false)->change();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }
};
