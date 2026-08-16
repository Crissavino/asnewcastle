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

    /** El presidente/dueño del proyecto (por teléfono, config OWNER_PHONE). */
    public function isOwner(): bool
    {
        $owner = config('app.owner_phone');

        return $owner && $this->member?->user?->phone === $owner;
    }

    /**
     * El dueño puede espiar la app "como jugador" (toggle en el header).
     * Solo cambia lo que se muestra: los permisos reales quedan intactos.
     */
    public function viewingAsPlayer(): bool
    {
        return $this->isOwner() && (bool) session('view_as_player', false);
    }

    /** Rol EFECTIVO para la vista: el dueño en modo espía figura como player. */
    public function actsAsManager(): bool
    {
        return (bool) $this->member?->isManager() && ! $this->viewingAsPlayer();
    }

    public function effectiveRole(): ?string
    {
        return $this->member ? ($this->actsAsManager() ? 'manager' : 'player') : null;
    }
}
