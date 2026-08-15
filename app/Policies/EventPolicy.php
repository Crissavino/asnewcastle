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
}
