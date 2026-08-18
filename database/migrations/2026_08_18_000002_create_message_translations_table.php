<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 2);
            $table->text('body');
            $table->timestamps();

            // Un mensaje se traduce una vez por idioma y nunca más (caché).
            $table->unique(['message_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_translations');
    }
};
