<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación in-app (la campanita). Una fila por jugador destinatario,
 * con estado leído/no leído. El body es {key, params} i18n, como los
 * mensajes de sistema del vestuario.
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use BelongsToClub, HasFactory;

    protected $fillable = [
        'club_id',
        'member_id',
        'type',
        'body_key',
        'body_params',
        'url',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'body_params' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
