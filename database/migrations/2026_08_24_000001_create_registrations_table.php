<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('season', 10);

            $table->string('full_name', 120)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality', 2)->nullable(); // ISO-2; 'RO' pide CNP

            // Cifrados en DB (cast 'encrypted'); se vacían en la purga
            $table->text('cnp')->nullable();
            $table->text('passport_number')->nullable();

            $table->string('photo_path')->nullable();         // foto carnet
            $table->string('id_doc_path')->nullable();        // copia CNP / doc identidad
            $table->string('passport_path')->nullable();      // copia pasaporte (no rumanos)
            $table->string('payment_proof_path')->nullable(); // comprobante (opcional)

            $table->text('previous_clubs')->nullable();
            $table->boolean('played_federated')->nullable();  // null = no contestó
            $table->text('federated_details')->nullable();

            $table->boolean('payment_marked')->default(false);
            $table->timestamp('consent_at')->nullable();

            $table->string('status', 20)->default('pendiente'); // pendiente|completo|enviado_federacion
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'member_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
