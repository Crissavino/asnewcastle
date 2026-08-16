<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Due;
use App\Models\Message;
use Illuminate\Console\Command;

class AnnounceDueDues extends Command
{
    protected $signature = 'cuotas:avisar';

    protected $description = 'El día del vencimiento publica en el vestuario quiénes deben — conciencia colectiva';

    public function handle(): int
    {
        Club::query()->each(function (Club $club) {
            $pending = Due::withoutGlobalScopes()
                ->where('club_id', $club->id)
                ->whereDate('due_date', today())
                ->where('status', 'pending')
                ->with('member.user:id,name')
                ->get();

            if ($pending->isEmpty()) {
                return;
            }

            $names = $pending
                ->map(fn ($d) => strtok($d->member->user->name ?? '', ' ') ?: $d->member->user->name)
                ->filter()
                ->implode(', ');

            Message::create([
                'club_id' => $club->id,
                'is_system' => true,
                'body' => json_encode([
                    'key' => 'system.dues_due',
                    'params' => [
                        'period' => $pending->first()->period->toDateString(),
                        'names' => $names,
                    ],
                ]),
            ]);
        });

        $this->info('Avisos de vencimiento publicados.');

        return self::SUCCESS;
    }
}
