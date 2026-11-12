<?php

namespace App\Services\Mollie;

use App\Models\Club;

/**
 * De quién es la plata: el club dueño de la cuenta de Mollie configurada. La
 * credencial de Mollie es una sola por instalación, así que el dueño es uno.
 *
 * Es la ÚNICA copia de esta regla: la consumen el libro de payouts y el saldo
 * inicial, y la va a consumir el chequeo diario. Corre sin request ni usuario,
 * así que lee con withoutGlobalScopes() y nunca confía en el scope de club.
 */
class ClubDeLaCuenta
{
    /** Está configurado explícitamente pero ese club no existe. */
    public const CONFIG_INEXISTENTE = 'club_configurado_inexistente';

    /** No hay ningún club en la tabla. */
    public const SIN_CLUB = 'sin_club';

    /** Hay varios clubes y nada dice de quién es la cuenta de Mollie. */
    public const AMBIGUO = 'club_ambiguo';

    /**
     * El club dueño, o el motivo por el que no se puede saber.
     *
     * @return array{0: ?Club, 1: ?string} [club, motivo]
     */
    public function resolver(): array
    {
        $configurado = config('services.mollie.club_id');

        if (filled($configurado)) {
            $club = Club::withoutGlobalScopes()->find((int) $configurado);

            return $club ? [$club, null] : [null, self::CONFIG_INEXISTENTE];
        }

        // Sin configuración, el dueño es el único club que haya. La propiedad no
        // se deduce de la cuota: ese es un campo de configuración que cambia
        // (el dueño la pone en 0 fuera de temporada).
        $clubs = Club::withoutGlobalScopes()->orderBy('id')->limit(2)->get();

        if ($clubs->count() === 1) {
            return [$clubs->first(), null];
        }

        return [null, $clubs->isEmpty() ? self::SIN_CLUB : self::AMBIGUO];
    }

    /** El club dueño, o null. Para quien no necesita el motivo. */
    public function club(): ?Club
    {
        return $this->resolver()[0];
    }
}
