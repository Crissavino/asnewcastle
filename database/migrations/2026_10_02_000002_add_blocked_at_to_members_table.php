<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Bloqueo de reingreso a ESTE club. Vive en la membresía, no en el
            // usuario: bloquear acá no deja a la persona afuera de otro club.
            // Mientras esté puesto, joinOrRejoin no le levanta la baja —ni por
            // el auto-join de club único ni con un link de invitación.
            $table->timestamp('blocked_at')->nullable()->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('blocked_at');
        });
    }
};
