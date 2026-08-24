<?php

namespace App\Console\Commands;

use App\Models\Registration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pasados los 90 días de la entrega se borran los documentos del disco
 * y los datos sensibles (CNP, pasaporte). La fila queda como registro
 * histórico de que el jugador se legitimó.
 */
class PurgeRegistrations extends Command
{
    protected $signature = 'legitimacion:purgar';

    protected $description = 'Borra documentos y datos sensibles de fichas de legitimación vencidas';

    public function handle(): int
    {
        $due = Registration::withoutGlobalScopes()
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->whereNull('purged_at')
            ->get();

        foreach ($due as $reg) {
            foreach (Registration::fileFields() as $column) {
                if ($reg->$column) {
                    Storage::delete($reg->$column);
                }
            }

            $reg->forceFill([
                'cnp' => null,
                'passport_number' => null,
                'photo_path' => null,
                'id_doc_path' => null,
                'passport_path' => null,
                'payment_proof_path' => null,
                'purged_at' => now(),
            ])->save();
        }

        $this->info("Purgadas: {$due->count()}");

        return self::SUCCESS;
    }
}
