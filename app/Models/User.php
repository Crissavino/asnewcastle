<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'phone',
        'phone_verified_at',
        'locale',
    ];

    protected $hidden = [
        'phone',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
        ];
    }

    /**
     * Nombre de pila y apellido. El `name` se guarda canónico ("Nombre
     * Apellido"), así que la primera palabra es el nombre y el resto el
     * apellido. Sirven para prellenar los dos campos del alta/perfil sin
     * columnas nuevas. Combinar: name = trim("$first $last").
     */
    public function firstName(): string
    {
        return explode(' ', trim((string) $this->name), 2)[0] ?? '';
    }

    public function lastName(): string
    {
        return explode(' ', trim((string) $this->name), 2)[1] ?? '';
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(Club::class, 'members')
            ->withPivot(['id', 'role', 'shirt_number', 'position', 'left_at'])
            ->withTimestamps();
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }
}
