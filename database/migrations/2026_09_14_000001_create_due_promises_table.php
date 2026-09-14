<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('due_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // promise | cant_pay
            $table->date('promised_for')->nullable(); // solo kind=promise
            $table->unsignedInteger('debt_cents'); // foto de la deuda al momento
            $table->string('status', 10)->nullable(); // promise: active|kept|broken|replaced
            $table->text('reason')->nullable(); // solo kind=cant_pay
            $table->timestamps();

            $table->index(['member_id', 'kind', 'status']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->date('due_popup_seen_on')->nullable()->after('vestuario_read_at');
            $table->unsignedSmallInteger('due_popup_seen_count')->default(0)->after('due_popup_seen_on');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['due_popup_seen_on', 'due_popup_seen_count']);
        });

        Schema::dropIfExists('due_promises');
    }
};
