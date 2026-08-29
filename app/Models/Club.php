<?php

namespace App\Models;

use Database\Factories\ClubFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    /** @use HasFactory<ClubFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'city',
        'league',
        'crest_path',
        'stripe_account_id',
        'stripe_onboarded_at',
        'monthly_fee_cents',
        'subscription_discount_cents',
        'currency',
        'standings_json',
        'standings_url',
        'fixture_url',
        'fixture_json',
    ];

    protected function casts(): array
    {
        return [
            'stripe_onboarded_at' => 'datetime',
            'standings_json' => 'array',
            'fixture_json' => 'array',
            'monthly_fee_cents' => 'integer',
            'subscription_discount_cents' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * Miembros activos VISIBLES del club: excluye a los ocultos (cuenta de
     * revisión) y a los que todavía no completaron el alta (sin dorsal → sin
     * nombre/puesto tampoco, porque el wizard setea todo junto). Todo listado
     * del plantel pasa por acá, así ni el oculto ni el que quedó a medias
     * aparecen en convocatorias, cuotas, estadísticas ni vestuario. El acceso
     * del propio member se resuelve por User::activeMembers(), que no filtra.
     */
    public function activeMembers(): HasMany
    {
        return $this->members()
            ->whereNull('left_at')
            ->where('members.hidden', false)
            ->where('members.role', '!=', 'coach')
            ->whereNotNull('shirt_number');
    }

    /**
     * Cuerpo técnico (entrenadores) activo y con el alta hecha (nombre). Va
     * aparte de activeMembers() a propósito: el técnico no juega, no paga cuota
     * ni entra en convocatorias, así que toda la maquinaria de jugadores lo
     * ignora; solo se lista como staff en el plantel.
     */
    public function coaches(): HasMany
    {
        return $this->members()
            ->whereNull('left_at')
            ->where('members.hidden', false)
            ->where('members.role', 'coach')
            ->whereHas('user', fn ($q) => $q->whereNotNull('name'));
    }
}
