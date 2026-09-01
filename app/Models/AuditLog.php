<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Support\CurrentClub;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Auditoría de acciones sensibles de cuota (ver migración). Inmutable:
 * solo se crea. Filtrado por club_id como todo lo demás.
 */
class AuditLog extends Model
{
    use BelongsToClub;

    // Log inmutable: solo importa cuándo se creó.
    public const UPDATED_AT = null;

    protected $fillable = [
        'club_id',
        'actor_member_id',
        'action',
        'subject_type',
        'subject_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'actor_member_id');
    }

    /**
     * Deja registro de una acción sobre un modelo. El autor es el miembro
     * activo de la request (null si es del sistema); el club_id lo pone el
     * global scope al crear.
     */
    public static function record(string $action, Model $subject, array $meta = []): void
    {
        static::create([
            'actor_member_id' => app(CurrentClub::class)->member()?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'meta' => $meta,
        ]);
    }

    /**
     * La última entrada de cada sujeto para una acción dada, con el autor
     * cargado. Devuelve una colección indexada por subject_id.
     *
     * @return Collection<int, AuditLog>
     */
    public static function latestFor(string $action, string $subjectType, iterable $ids): Collection
    {
        $ids = collect($ids);

        if ($ids->isEmpty()) {
            return collect();
        }

        return static::query()
            ->where('action', $action)
            ->where('subject_type', $subjectType)
            ->whereIn('subject_id', $ids)
            ->with('actor.user:id,name')
            ->orderByDesc('id')
            ->get()
            ->unique('subject_id')   // ordenado desc → el primero es el último
            ->keyBy('subject_id');
    }
}
