<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de auditoría de acciones sensibles de cuota: quién cambió el tipo
 * de cuota de un jugador (normal/becado/personalizada) y quién marcó una cuota
 * a mano (pagada/condonada/pendiente). Solo lo ve el manager. Es un log
 * inmutable: se inserta, no se edita ni se borra a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            // Quién lo hizo. Nullable: una acción del sistema no tiene autor,
            // y si el miembro se borra la fila de auditoría sobrevive.
            $table->foreignId('actor_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('action');                 // fee_type.set | due.status.set
            $table->string('subject_type');           // App\Models\Member | App\Models\Due
            $table->unsignedBigInteger('subject_id');
            $table->json('meta')->nullable();         // {from, to, amount_cents}
            $table->timestamp('created_at')->nullable();

            $table->index(['club_id', 'subject_type', 'subject_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
