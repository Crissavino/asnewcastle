<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Carbon\CarbonInterface;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToClub, HasFactory;

    /** Ventana para el evento cargado después del corte: hasta 3hs antes. */
    private const LATE_RSVP_HOURS = 3;

    protected $fillable = [
        'club_id',
        'created_by_member_id',
        'kind',
        'opponent',
        'is_home',
        'starts_at',
        'kickoff_at',
        'venue',
        'venue_url',
        'kit',
        'notes',
        'staff_notes',
        'notified_at',
        'reminded_at',
        'mvp_opened_at',
        'mvp_closed_at',
        'attendance_confirmed_at',
        'cancelled_at',
        'goals_for',
        'goals_against',
    ];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'starts_at' => 'datetime',
            'kickoff_at' => 'datetime',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
            'mvp_opened_at' => 'datetime',
            'mvp_closed_at' => 'datetime',
            'attendance_confirmed_at' => 'datetime',
            'staff_notes_updated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'goals_for' => 'integer',
            'goals_against' => 'integer',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * A quién se le recuerda: al plantel activo menos los que ya definieron
     * (Voy / No voy). El que está en duda todavía no confirmó, así que entra.
     */
    public function membersToRemind(): HasMany
    {
        $decided = $this->attendances()->whereIn('status', ['in', 'out'])->select('member_id');

        return $this->club->activeMembers()->whereNotIn('members.id', $decided);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'created_by_member_id');
    }

    /** Quién tocó el bloc del cuerpo técnico por última vez. */
    public function staffNotesEditor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'staff_notes_updated_by_member_id');
    }

    public function isMatch(): bool
    {
        return $this->kind === 'match';
    }

    /**
     * Partidos y entrenamientos ya terminados, con el mismo criterio que
     * isFinished(): dos horas desde el kick-off, o desde el encuentro si no
     * está cargado. Que la lista y las estadísticas no discutan entre ellas.
     */
    public function scopeFinished(Builder $query): void
    {
        $query->whereRaw('COALESCE(kickoff_at, starts_at) < ?', [now()->subHours(2)]);
    }

    public function mvpVotes(): HasMany
    {
        return $this->hasMany(MvpVote::class);
    }

    public function playerRatings(): HasMany
    {
        return $this->hasMany(PlayerRating::class);
    }

    /**
     * Un partido se considera terminado 2 horas después del kick-off (si está
     * cargado) o del encuentro. starts_at es "nos juntamos", no "se juega".
     */
    public function isFinished(): bool
    {
        return ($this->kickoff_at ?? $this->starts_at)->copy()->addHours(2)->isPast();
    }

    /**
     * Hasta cuándo el plantel puede anotarse o bajarse: las 22:00 del día
     * anterior al evento. El que se hace el boludo esperando a ver quién va
     * se queda afuera, y el manager arma el equipo con la lista cerrada.
     *
     * Evento cargado después de esa hora (el amistoso que se arma sobre la
     * fecha): nacería cerrado, así que se le abre una ventana hasta 3hs antes
     * del encuentro, nunca menos de una hora desde que se creó y nunca más
     * allá del inicio.
     */
    public function rsvpClosesAt(): CarbonInterface
    {
        $closes = $this->starts_at->copy()->subDay()->setTime(22, 0);

        if ($this->created_at && $this->created_at->greaterThanOrEqualTo($closes)) {
            $closes = $this->starts_at->copy()->subHours(self::LATE_RSVP_HOURS);

            if ($closes->lessThan($this->created_at->copy()->addHour())) {
                $closes = $this->created_at->copy()->addHour();
            }
        }

        return $closes->greaterThan($this->starts_at) ? $this->starts_at->copy() : $closes;
    }

    /** Si todavía se puede responder Voy / Duda / No voy. */
    public function rsvpOpen(): bool
    {
        return $this->rsvpClosesAt()->isFuture();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function hasResult(): bool
    {
        return $this->goals_for !== null && $this->goals_against !== null;
    }

    /**
     * Goles y asistencias ya repartidos entre los presentes. El marcador no
     * puede quedar por debajo de esto: nadie metió más goles que el equipo.
     */
    public function creditedGoals(): int
    {
        return (int) $this->attendances()->where('attended', true)->sum('goals');
    }

    public function creditedAssists(): int
    {
        return (int) $this->attendances()->where('attended', true)->sum('assists');
    }

    /**
     * Ids de los que estuvieron: los presentes confirmados por el manager o,
     * si todavía no confirmó, los que dijeron "Voy".
     */
    public function presentMemberIds(): Collection
    {
        $attendances = $this->relationLoaded('attendances')
            ? $this->attendances
            : $this->attendances()->get();

        return $this->attendance_confirmed_at
            ? $attendances->where('attended', true)->pluck('member_id')->values()
            : $attendances->where('status', 'in')->pluck('member_id')->values();
    }

    public function wasPresent(int $memberId): bool
    {
        return $this->presentMemberIds()->contains($memberId);
    }

    /** La votación de figura queda abierta 48hs desde el final del partido. */
    public function mvpPollOpen(): bool
    {
        return $this->mvp_opened_at !== null
            && $this->isFinished()
            && $this->starts_at->copy()->addHours(50)->isFuture();
    }
}
