<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\FcmPushSender;
use App\Services\Push\PushSender;
use Illuminate\Console\Command;

/**
 * Diagnóstico de push nativas: manda una notificación de prueba a los tokens
 * registrados y muestra la respuesta cruda de FCM (para ver si el APNs de iOS
 * está bien configurado). Uso: `php artisan push:probe [phone] [--platform=ios]`.
 */
class PushProbe extends Command
{
    protected $signature = 'push:probe {phone? : Solo los dispositivos de este teléfono (E.164)} {--platform=ios : ios|android|web|all}';

    protected $description = 'Envía una push de diagnóstico y muestra la respuesta de FCM';

    public function handle(PushSender $push): int
    {
        $query = DeviceToken::query();

        if (($platform = $this->option('platform')) !== 'all') {
            $query->where('platform', $platform);
        }

        if ($phone = $this->argument('phone')) {
            $userId = User::where('phone', $phone)->value('id');
            if (! $userId) {
                $this->error("No hay usuario con teléfono {$phone}");

                return self::FAILURE;
            }
            $query->where('user_id', $userId);
        }

        $tokens = $query->get();
        $this->info("Tokens encontrados: {$tokens->count()} (platform={$platform})");

        if ($tokens->isEmpty()) {
            $this->warn('No hay tokens registrados: el dispositivo todavía no se registró (¿abrió la app y aceptó notificaciones?).');

            return self::SUCCESS;
        }

        foreach ($tokens as $t) {
            $result = $push instanceof FcmPushSender
                ? $push->probe($t->token)
                : ['sent' => $push->send([$t->token], '⚽ Prueba New Castle', 'Push de diagnóstico')];

            $this->line("user={$t->user_id} platform={$t->platform} token=".substr($t->token, 0, 12).'…');
            $this->line('  → '.json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
