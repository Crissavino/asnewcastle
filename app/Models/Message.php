<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use BelongsToClub, HasFactory;

    protected $fillable = [
        'club_id',
        'member_id',
        'body',
        'attachment_path',
        'is_system',
        'detected_locale',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Traducciones cacheadas del mensaje, una por idioma. */
    public function translations(): HasMany
    {
        return $this->hasMany(MessageTranslation::class);
    }
}
