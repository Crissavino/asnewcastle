<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Para poder borrar una cuenta (requisito de Google Play), un miembro tiene que
// poder eliminarse aunque haya CREADO eventos o CARGADO gastos. Esas dos FK eran
// RESTRICT; se pasan a nullable + nullOnDelete: el evento/gasto del club sobrevive,
// solo pierde la atribución de quién lo creó/cargó (nada en la UI la muestra).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['created_by_member_id']);
            $table->foreignId('created_by_member_id')->nullable()->change();
            $table->foreign('created_by_member_id')->references('id')->on('members')->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->foreignId('member_id')->nullable()->change();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['created_by_member_id']);
            $table->foreignId('created_by_member_id')->nullable(false)->change();
            $table->foreign('created_by_member_id')->references('id')->on('members');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->foreignId('member_id')->nullable(false)->change();
            $table->foreign('member_id')->references('id')->on('members');
        });
    }
};
