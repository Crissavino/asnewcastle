<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los dados de baja se quedaban con el dorsal: invisible para el wizard (que
 * solo mira a los activos) pero bien visible para el unique(club_id,
 * shirt_number) de la base. El jugador nuevo que elegía ese número pasaba la
 * validación, chocaba contra la base y se quedaba trabado en el alta.
 *
 * A partir de ahora la baja libera el dorsal (PerfilController::removeMember);
 * esto limpia a los que ya estaban de baja con el número puesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('members')
            ->whereNotNull('left_at')
            ->whereNotNull('shirt_number')
            ->update(['shirt_number' => null]);
    }

    public function down(): void
    {
        // No hay vuelta atrás: el dorsal pudo habérselo quedado otro jugador.
    }
};
