<?php

use App\Models\Member;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * La disponibilidad pasa de slots fijos (tue-2030, sat-am...) a días
     * de la semana (mon..sun). Se migran los datos existentes.
     */
    public function up(): void
    {
        $map = [
            'tue-2030' => 'tue',
            'thu-2030' => 'thu',
            'sat-am' => 'sat',
            'sun-am' => 'sun',
        ];

        Member::withoutGlobalScopes()->whereNotNull('availability')->each(function (Member $member) use ($map) {
            $member->update([
                'availability' => array_values(array_unique(array_map(
                    fn ($slot) => $map[$slot] ?? $slot,
                    $member->availability,
                ))),
            ]);
        });
    }

    public function down(): void
    {
        // Sin vuelta atrás: los días nuevos no mapean 1 a 1 con los slots viejos
    }
};
