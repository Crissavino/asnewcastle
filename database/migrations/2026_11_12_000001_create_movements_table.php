<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('pot', 10); // cuenta | cash
            $table->string('kind', 20); // payout | cuota | gasto | transferencia | ingreso | ajuste | fee | saldo_inicial
            $table->bigInteger('amount_cents'); // con signo: + entra, − sale
            $table->date('occurred_on');
            $table->uuid('group_uuid')->nullable(); // ata las dos filas de una transferencia
            $table->foreignId('reverses_movement_id')->nullable()->constrained('movements')->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('due_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mollie_payout_id')->nullable();
            $table->string('note', 200)->nullable(); // obligatoria si kind = ajuste
            $table->foreignId('created_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();

            $table->index('group_uuid');
            $table->index(['club_id', 'pot']);
            $table->index(['club_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};
