<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('player'); // player | manager
            $table->unsignedTinyInteger('shirt_number')->nullable();
            $table->string('position', 3)->nullable(); // ARQ | DEF | MED | DEL
            $table->string('preferred_foot', 20)->nullable();
            $table->json('availability')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'user_id']);
            $table->unique(['club_id', 'shirt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
