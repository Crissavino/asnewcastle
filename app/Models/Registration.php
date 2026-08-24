<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ficha de legitimación en la Federación: una por jugador y temporada.
 * CNP y pasaporte viajan cifrados y se purgan a los 90 días de la entrega;
 * la fila queda como registro histórico.
 */
class Registration extends Model
{
    use BelongsToClub;
    use HasFactory;

    public const STATUS_PENDIENTE = 'pendiente';

    public const STATUS_COMPLETO = 'completo';

    public const STATUS_ENVIADO = 'enviado_federacion';

    protected $guarded = [];

    // firstOrCreate no relee los defaults de la DB: sin esto, una ficha
    // recién creada llega al front con status null y figura "completa"
    protected $attributes = ['status' => self::STATUS_PENDIENTE];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'cnp' => 'encrypted',
            'passport_number' => 'encrypted',
            'played_federated' => 'boolean',
            'payment_marked' => 'boolean',
            'consent_at' => 'datetime',
            'submitted_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Campos que son archivos: input del form => columna con el path. */
    public static function fileFields(): array
    {
        return [
            'photo' => 'photo_path',
            'id_doc' => 'id_doc_path',
            'passport' => 'passport_path',
            'payment_proof' => 'payment_proof_path',
        ];
    }

    /**
     * Qué le falta para estar completa. El comprobante de pago y los
     * clubes anteriores son opcionales; todo lo demás no.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $missing = [];

        if (! $this->full_name) {
            $missing[] = 'full_name';
        }
        if (! $this->birth_date) {
            $missing[] = 'birth_date';
        }
        if (! $this->nationality) {
            $missing[] = 'nationality';
        }
        if ($this->nationality === 'RO') {
            if (! $this->cnp) {
                $missing[] = 'cnp';
            }
        } elseif ($this->nationality) {
            if (! $this->passport_number) {
                $missing[] = 'passport_number';
            }
            if (! $this->passport_path) {
                $missing[] = 'passport';
            }
        }
        if (! $this->photo_path) {
            $missing[] = 'photo';
        }
        if (! $this->id_doc_path) {
            $missing[] = 'id_doc';
        }
        if ($this->played_federated === null) {
            $missing[] = 'played_federated';
        }
        if ($this->played_federated && ! $this->federated_details) {
            $missing[] = 'federated_details';
        }
        if (! $this->payment_marked) {
            $missing[] = 'payment';
        }
        if (! $this->consent_at) {
            $missing[] = 'consent';
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /** Recalcula status/submitted_at/purge_after tras cada guardado parcial. */
    public function refreshStatus(): void
    {
        if ($this->status === self::STATUS_ENVIADO) {
            return; // el manager ya la presentó; no se pisa
        }

        if ($this->isComplete()) {
            if ($this->status !== self::STATUS_COMPLETO) {
                $this->status = self::STATUS_COMPLETO;
                $this->submitted_at = now();
                $this->purge_after = now()->addDays((int) config('legitimacion.purge_days'));
            }
        } else {
            $this->status = self::STATUS_PENDIENTE;
            $this->submitted_at = null;
            $this->purge_after = null;
        }

        $this->save();
    }
}
