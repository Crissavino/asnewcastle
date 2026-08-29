<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\Member;
use App\Models\User;
use App\Services\Push\PushSender;
use Illuminate\Console\Command;

/**
 * Manda al dueño (OWNER_PHONE), por push, los últimos N miembros que se
 * registraron (por fecha de alta al club). Vía alternativa cuando el output
 * de los comandos de Forge no está disponible.
 *
 *   php artisan app:ultimos-push {n=2}
 */
class UltimosPush extends Command
{
    protected $signature = 'app:ultimos-push {n=2}';

    protected $description = 'Push al dueño con los últimos N miembros registrados';

    public function handle(PushSender $push): int
    {
        $n = max(1, (int) $this->argument('n'));

        $ultimos = Member::withoutGlobalScopes()
            ->whereNull('left_at')
            ->with('user')
            ->orderByDesc('id')
            ->limit($n)
            ->get();

        $lines = $ultimos
            ->map(fn (Member $m) => trim(($m->user?->name ?: 'sin nombre').' · '.($m->user?->phone ?? '—').' · '.$m->role))
            ->all();

        $owner = User::where('phone', config('app.owner_phone'))->first();
        $tokens = $owner
            ? DeviceToken::query()->where('user_id', $owner->id)->pluck('token')->all()
            : [];

        $push->send($tokens, "🆕 Últimos {$n} registrados", implode(' | ', $lines));

        $this->info("Enviado al dueño (".count($tokens)." tokens):");
        foreach ($lines as $l) {
            $this->line('  '.$l);
        }

        return self::SUCCESS;
    }
}
