<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // cuenta | cash. Nullable: los gastos anteriores quedan sin bolsillo
            // y fuera de los saldos.
            $table->string('pot', 10)->nullable()->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('pot');
        });
    }
};
