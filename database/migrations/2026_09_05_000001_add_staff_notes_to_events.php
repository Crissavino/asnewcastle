<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Bloc de notas del cuerpo técnico: un solo texto compartido por
            // evento, editable por manager y coach. El jugador no lo ve nunca.
            $table->text('staff_notes')->nullable()->after('notes');
            $table->unsignedBigInteger('staff_notes_updated_by_member_id')->nullable()->after('staff_notes');
            $table->timestamp('staff_notes_updated_at')->nullable()->after('staff_notes_updated_by_member_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['staff_notes', 'staff_notes_updated_by_member_id', 'staff_notes_updated_at']);
        });
    }
};
