<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('body');
        });

        Schema::table('events', function (Blueprint $table) {
            // Cuándo se abrió la votación de figura post-partido
            $table->timestamp('mvp_opened_at')->nullable()->after('reminded_at');
        });

        Schema::create('mvp_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voter_member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('voted_member_id')->constrained('members')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'voter_member_id']);
        });

        // Calificación ternaria post-partido (1 le costó · 2 cumplió · 3 crack).
        // Anónima hacia afuera: el rater se guarda solo para la unicidad.
        Schema::create('player_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rater_member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('rated_member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1 | 2 | 3
            $table->timestamps();

            $table->unique(['event_id', 'rater_member_id', 'rated_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_ratings');
        Schema::dropIfExists('mvp_votes');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('mvp_opened_at'));
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('attachment_path'));
    }
};
