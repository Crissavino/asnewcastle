<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_member_id')->constrained('members');
            $table->string('kind', 10); // match | training
            $table->string('opponent')->nullable();
            $table->boolean('is_home')->default(true);
            $table->dateTime('starts_at');
            $table->string('venue');
            $table->string('kit', 5)->default('home'); // home | away
            $table->text('notes')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
