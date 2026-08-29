<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `detected_locale` era varchar(2) (para 'es', 'ro', etc.), pero el detector
 * ahora puede devolver 'und' (idioma desconocido) — 3 chars — y cualquier
 * mensaje que caiga ahí rompía el insert. Se ensancha a 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('detected_locale', 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('detected_locale', 2)->nullable()->change();
        });
    }
};
