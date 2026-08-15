<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('city')->nullable();
            $table->string('league')->nullable();
            $table->string('crest_path')->nullable();
            $table->string('stripe_account_id')->nullable();
            $table->timestamp('stripe_onboarded_at')->nullable();
            $table->unsignedInteger('monthly_fee_cents')->default(0);
            $table->string('currency', 3)->default('RON');
            $table->json('standings_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
