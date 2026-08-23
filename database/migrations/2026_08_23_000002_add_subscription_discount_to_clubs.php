<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // Descuento en centavos que se aplica a la cuota del jugador si se
            // suscribe al débito automático (incentivo). 0 = sin descuento.
            $table->unsignedInteger('subscription_discount_cents')->default(0)->after('monthly_fee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('subscription_discount_cents');
        });
    }
};
