<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('status', 6); // in | maybe | out
            $table->timestamp('responded_at');
            $table->string('source', 8)->default('app'); // app | whatsapp

            $table->unique(['event_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
