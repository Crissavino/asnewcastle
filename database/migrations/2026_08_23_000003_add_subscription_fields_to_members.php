<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Débito automático vía Stripe Subscriptions (sobre la cuenta conectada).
            // El customer y la subscription viven en la cuenta conectada del club.
            $table->string('stripe_customer_id')->nullable()->after('custom_fee_cents');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');
            // null = sin suscripción · active · past_due (falló el cobro) · canceled
            $table->string('subscription_status', 20)->nullable()->after('stripe_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id', 'subscription_status']);
        });
    }
};
