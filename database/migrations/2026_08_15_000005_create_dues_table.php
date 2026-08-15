<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('period'); // día 1 del mes
            $table->unsignedInteger('amount_cents');
            $table->string('status', 8)->default('pending'); // pending | paid | waived
            $table->date('due_date');
            $table->timestamps();

            $table->unique(['club_id', 'member_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dues');
    }
};
