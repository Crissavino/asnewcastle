<?php

namespace App\Support;

use App\Models\Club;
use App\Models\Member;

/**
 * Contenedor del club activo de la request. Lo resuelve el middleware
 * SetActiveClub y de acá lo leen los global scopes y los controladores.
 * Todo query de datos de club tiene que pasar por este club — nunca
 * por un club_id que venga del cliente.
 */
class CurrentClub
{
    protected ?Club $club = null;

    protected ?Member $member = null;

    public function set(Club $club, Member $member): void
    {
        $this->club = $club;
        $this->member = $member;
    }

    public function club(): ?Club
    {
        return $this->club;
    }

    public function member(): ?Member
    {
        return $this->member;
    }

    public function id(): ?int
    {
        return $this->club?->id;
    }

    /**
     * 404 si el modelo no es del club activo. Necesario en rutas con
     * binding implícito: la resolución del modelo corre antes que el
     * middleware SetActiveClub, así que el global scope no filtra ahí.
     */
    public function assertOwns(object $model): void
    {
        abort_unless($this->club && $model->club_id === $this->club->id, 404);
    }
}
