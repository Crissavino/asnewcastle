<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mollie al lado de Stripe: el club cobra en su PROPIA cuenta Mollie (RON),
 * así que no hay Connect/Express por club — solo customer + mandato + subscription
 * por jugador. Se agregan las columnas espejo de las de Stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('mollie_customer_id')->nullable()->after('subscription_status');
            $table->string('mollie_subscription_id')->nullable()->after('mollie_customer_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            // Un pago puede venir de Stripe o de Mollie
            $table->string('provider')->default('stripe')->after('due_id');
            $table->string('mollie_payment_id')->nullable()->unique()->after('stripe_payment_intent_id');
            // El id de Stripe deja de ser obligatorio (los pagos Mollie no lo tienen)
            $table->string('stripe_payment_intent_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['mollie_customer_id', 'mollie_subscription_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['provider', 'mollie_payment_id']);
        });
    }
};
