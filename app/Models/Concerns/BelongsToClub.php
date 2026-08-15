<?php

namespace App\Models\Concerns;

use App\Support\CurrentClub;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Global scope de aislamiento por club: todo modelo con club_id lo usa.
 * Filtra automáticamente por el club activo y lo asigna al crear.
 */
trait BelongsToClub
{
    public static function bootBelongsToClub(): void
    {
        static::addGlobalScope('club', function (Builder $builder) {
            $clubId = app(CurrentClub::class)->id();

            if ($clubId !== null) {
                $builder->where($builder->getModel()->getTable().'.club_id', $clubId);
            }
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('club_id') === null) {
                $model->setAttribute('club_id', app(CurrentClub::class)->id());
            }
        });
    }
}
