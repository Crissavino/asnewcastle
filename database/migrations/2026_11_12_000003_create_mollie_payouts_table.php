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
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            // El unique es la idempotencia del webhook: un payout, una fila.
            $table->string('mollie_payout_id')->unique();
            $table->string('status', 30); // completed | failed | canceled | ...
            $table->bigInteger('amount_cents'); // lo que Mollie depositó (neto)
            $table->bigInteger('fee_cents')->nullable(); // null = Mollie no la informó
            $table->string('currency', 3);
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
