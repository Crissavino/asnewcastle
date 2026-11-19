<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Due;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja a un jugador becado: no se le genera más cuota y sus cuotas
 * pendientes quedan condonadas. Las ya pagadas no se tocan (la plata entró).
 */
class Becar extends Command
{
    protected $signature = 'app:becar {nombre} {modo=dry}';

    protected $description = 'Beca a un jugador y condona sus cuotas pendientes (dry por defecto)';

    public function handle(): int
    {
        $apply = $this->argument('modo') === 'aplicar';
        $nombre = $this->argument('nombre');

        $members = Member::withoutGlobalScopes()
            ->with('user:id,name')
            ->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$nombre}%"))
            ->get();

        if ($members->count() !== 1) {
            $this->error($members->isEmpty()
                ? "No hay jugador que matchee \"{$nombre}\"."
                : 'Matchean varios: '.$members->pluck('user.name')->implode(', '));

            return self::FAILURE;
        }

        $member = $members->first();
        $pendientes = Due::withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->orderBy('period')
            ->get();

        $this->line($member->user->name.' (member #'.$member->id.')');
        $this->line('  tipo de cuota: '.$member->fee_type.' → becado');
        $this->line('  cuotas pendientes a condonar: '.$pendientes->count());

        foreach ($pendientes as $d) {
            $this->line('    cuota #'.$d->id.' '.substr((string) $d->period, 0, 7).' '.
                number_format($d->amount_cents / 100, 2).' → waived');
        }

        if ($member->subscription_status === 'active') {
            $this->warn('  OJO: tiene débito automático ACTIVO — hay que cancelarlo aparte o le sigue cobrando.');
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('DRY RUN — nada se guardó. Para aplicar: ... '.$nombre.' aplicar');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($member, $pendientes) {
            $member->forceFill(['fee_type' => 'becado', 'custom_fee_cents' => null])->save();

            AuditLog::withoutGlobalScopes()->create([
                'club_id' => $member->club_id,
                'actor_member_id' => null,
                'action' => 'fee_type.set',
                'subject_type' => $member->getMorphClass(),
                'subject_id' => $member->id,
                'meta' => ['to' => 'becado', 'motivo' => 'junior becado'],
            ]);

            foreach ($pendientes as $due) {
                $due->forceFill(['status' => 'waived'])->save();

                AuditLog::withoutGlobalScopes()->create([
                    'club_id' => $due->club_id,
                    'actor_member_id' => null,
                    'action' => 'due.status.set',
                    'subject_type' => $due->getMorphClass(),
                    'subject_id' => $due->id,
                    'meta' => ['from' => 'pending', 'to' => 'waived', 'pot' => null, 'motivo' => 'junior becado'],
                ]);
            }
        });

        $this->info('Hecho.');

        return self::SUCCESS;
    }
}
