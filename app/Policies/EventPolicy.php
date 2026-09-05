<?php

namespace App\Policies;

use App\Models\User;
use App\Support\CurrentClub;

class EventPolicy
{
    /** Solo el manager del club activo crea eventos y manda recordatorios. */
    public function create(User $user): bool
    {
        return app(CurrentClub::class)->member()?->isManager() ?? false;
    }

    /**
     * El bloc de notas del cuerpo técnico: manager o coach, por ROL REAL —
     * el toggle "ver como jugador" esconde el bloc pero no quita el permiso.
     */
    public function annotate(User $user, \App\Models\Event $event): bool
    {
        $member = app(CurrentClub::class)->member();

        return ($member?->isManager() || $member?->isCoach()) ?? false;
    }
}
