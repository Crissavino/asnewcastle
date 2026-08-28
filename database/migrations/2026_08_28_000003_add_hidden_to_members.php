<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Miembro oculto: no aparece en ningún listado del plantel (convocatorias,
 * cuotas, estadísticas, vestuario) pero conserva su acceso. Se usa para la
 * cuenta de revisión de Apple/Google, que necesita ver toda la app sin
 * ensuciar el plantel real ni recibir cuotas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }
};
