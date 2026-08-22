<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Services\Push\FcmPushSender;
use App\Services\Push\PushSender;
use Illuminate\Console\Command;

class PushDiag extends Command
{
    protected $signature = 'push:diag {platform=ios}';

    protected $description = 'Envía una push de prueba a un token de la plataforma dada y guarda la respuesta cruda de FCM en public/pd.txt';

    public function handle(PushSender $sender): int
    {
        $token = DeviceToken::where('platform', $this->argument('platform'))->latest('id')->value('token');

        if (! $token) {
            file_put_contents(public_path('pd.txt'), 'sin token para '.$this->argument('platform'));

            return self::SUCCESS;
        }

        $out = 'driver='.class_basename($sender);

        if ($sender instanceof FcmPushSender) {
            $r = $sender->probe($token);
            $out .= ' | '.json_encode($r);
        } else {
            $out .= ' | (no es FcmPushSender, no se puede diagnosticar)';
        }

        file_put_contents(public_path('pd.txt'), $out);
        $this->info('escrito en public/pd.txt');

        return self::SUCCESS;
    }
}
