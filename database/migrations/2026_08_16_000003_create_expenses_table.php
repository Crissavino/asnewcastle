<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained(); // quién lo cargó
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 16); // referee | pitch | league | gear | water | other
            $table->string('description')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->date('spent_on');
            $table->timestamps();

            $table->index(['club_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
