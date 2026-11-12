<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mollie_payouts', function (Blueprint $table) {
            $table->id();
            // Nullable: un payout que no se pudo acreditar (no se sabe de qué
            // club es la cuenta) igual se guarda, para que se VEA.
            $table->foreignId('club_id')->nullable()->constrained()->cascadeOnDelete();
            // El unique es la idempotencia del webhook: un payout, una fila.
            $table->string('mollie_payout_id')->unique();
            $table->string('status', 30); // completed | failed | canceled | ...
            $table->bigInteger('amount_cents'); // lo que Mollie depositó (neto)
            $table->bigInteger('fee_cents')->nullable(); // null = Mollie no la informó
            $table->string('currency', 3)->nullable();
            // Por qué quedó sin acreditar (null = acreditado o contra-asentado).
            $table->string('pending_reason', 40)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mollie_payouts');
    }
};
