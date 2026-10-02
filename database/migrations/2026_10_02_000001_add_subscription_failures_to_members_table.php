<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Cuántos cobros recurrentes seguidos fallaron. Mollie reintenta
            // hasta 5 veces (una por día) y después cancela la suscripción:
            // avisamos en el 1° y en el 4° (la anteúltima). Vuelve a 0 con
            // cada cobro exitoso.
            $table->unsignedTinyInteger('subscription_failures')->default(0)->after('subscription_status');
            // Último pago fallido imputado al contador. El webhook de Mollie
            // puede repetirse: sin esto, un reenvío contaría dos veces.
            $table->string('subscription_last_failure_id', 64)->nullable()->after('subscription_failures');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['subscription_failures', 'subscription_last_failure_id']);
        });
    }
};
