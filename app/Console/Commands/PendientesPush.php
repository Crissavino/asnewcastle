<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\Member;
use App\Models\User;
use App\Services\Push\PushSender;
use Illuminate\Console\Command;

/**
 * Manda al dueño (OWNER_PHONE), por push a su teléfono, la lista de miembros
 * que se loguearon pero no completaron el alta (sin dorsal) con su número,
 * para poder reclamarles. Se usa como vía alternativa cuando el output de los
 * comandos de Forge no anda: la push llega igual al dispositivo.
 */
class PendientesPush extends Command
{
    protected $signature = 'app:pendientes-push';

    protected $description = 'Envía al dueño, por push, los teléfonos de los que no completaron el alta';

    public function handle(PushSender $push): int
    {
        $pending = Member::withoutGlobalScopes()
            ->whereNull('left_at')
            ->where('hidden', false)
            ->whereNull('shirt_number')
            ->with('user')
            ->get();

        $lines = $pending
            ->map(fn (Member $m) => trim(($m->user?->name ?: 'sin nombre').' '.($m->user?->phone ?? '—')))
            ->all();

        $owner = User::where('phone', config('app.owner_phone'))->first();
        $tokens = $owner
            ? DeviceToken::query()->where('user_id', $owner->id)->pluck('token')->all()
            : [];

        $title = '📋 Pendientes de alta ('.$pending->count().')';
        $body = $pending->isEmpty() ? 'No quedan pendientes 🎉' : implode(' · ', $lines);

        $push->send($tokens, $title, $body);

        $this->info("Enviado al dueño: {$pending->count()} pendientes, ".count($tokens).' tokens.');
        foreach ($lines as $l) {
            $this->line('  '.$l);
        }

        return self::SUCCESS;
    }
}
