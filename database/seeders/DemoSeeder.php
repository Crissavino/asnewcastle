<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Club;
use App\Models\Due;
use App\Models\Event;
use App\Models\Member;
use App\Models\Message;
use App\Models\MvpVote;
use App\Models\PlayerRating;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de demo para ver la app viva: plantel, un partido recién
 * terminado con la votación de figura abierta, agenda con eventos
 * futuros, chat con mensajes y una foto, y la caja a medio cobrar.
 *
 * Correr con: php artisan db:seed --class=DemoSeeder
 * Es re-ejecutable: no duplica nada.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $club = Club::where('slug', 'as-new-castle')->firstOrFail();
        $manager = $club->activeMembers()->where('role', 'manager')->firstOrFail();

        $members = $this->plantel($club);

        [$terminado, $proximo] = $this->eventos($club, $manager, $members);

        $this->votacion($terminado, $members);
        $this->chat($club, $members, $terminado);
        $this->cuotas($club, $members);

        $this->command?->info('Demo lista: plantel de '.(count($members) + 1).', figura abierta, agenda y caja pobladas.');
    }

    /** @return array<int, Member> */
    protected function plantel(Club $club): array
    {
        $roster = [
            ['Sergio Quiroga', 5, 'MED'],
            ['Fabián Rodríguez', 7, 'DEL'],
            ['Andrez Rodríguez', 4, 'DEF'],
            ['Mihai Ionescu', 1, 'ARQ'],
            ['Răzvan Popa', 3, 'DEF'],
            ['Diego Ferreyra', 9, 'DEL'],
            ['Andrei Marin', 6, 'MED'],
            ['Camilo Restrepo', 11, 'DEL'],
            ['Vlad Dumitru', 2, 'DEF'],
            ['Tomás Aguirre', 8, 'MED'],
        ];

        $taken = $club->activeMembers()->whereNotNull('shirt_number')->pluck('shirt_number')->all();
        $members = [];

        foreach ($roster as $i => [$name, $number, $position]) {
            $user = User::firstOrCreate(
                ['phone' => sprintf('+407000000%02d', $i)],
                ['name' => $name, 'locale' => 'es', 'phone_verified_at' => now()],
            );

            $member = $club->members()->where('user_id', $user->id)->first();

            if (! $member) {
                if (in_array($number, $taken, true)) {
                    for ($number = 12; in_array($number, $taken, true); $number++);
                }
                $taken[] = $number;

                $member = $club->members()->create([
                    'user_id' => $user->id,
                    'role' => 'player',
                    'shirt_number' => $number,
                    'position' => $position,
                    'preferred_foot' => 'right',
                    'availability' => ['tue', 'sat'],
                    'joined_at' => now()->subMonths(2),
                ]);
            }

            $members[] = $member;
        }

        return $members;
    }

    /** @return array{0: Event, 1: Event} */
    protected function eventos(Club $club, Member $manager, array $members): array
    {
        // Partido terminado hace 3 horas, con la votación de figura abierta
        $terminado = Event::firstOrCreate(
            ['club_id' => $club->id, 'opponent' => 'CS Cernica'],
            [
                'created_by_member_id' => $manager->id,
                'kind' => 'match',
                'is_home' => true,
                'starts_at' => now()->subHours(3),
                'venue' => 'Teren Voluntari — principal',
                'kit' => 'home',
                'notified_at' => now()->subDay(),
                'mvp_opened_at' => now()->subHour(),
            ],
        );

        // Los primeros siete fueron (más vos), uno en duda, uno no fue
        foreach (array_slice($members, 0, 7) as $m) {
            $this->asistencia($terminado, $m, 'in');
        }
        $this->asistencia($terminado, $manager, 'in');
        $this->asistencia($terminado, $members[7], 'maybe');
        $this->asistencia($terminado, $members[8], 'out');

        // Próximo partido: sábado que viene a las 11
        $proximo = Event::firstOrCreate(
            ['club_id' => $club->id, 'opponent' => 'CS Afumați II'],
            [
                'created_by_member_id' => $manager->id,
                'kind' => 'match',
                'is_home' => false,
                'starts_at' => now()->next('Saturday')->setTime(11, 0),
                'venue' => 'Teren Afumați',
                'kit' => 'away',
                'notes' => 'Liga a V-a Ilfov · Etapa 2',
                'notified_at' => now()->subHours(5),
            ],
        );

        foreach (array_slice($members, 0, 5) as $m) {
            $this->asistencia($proximo, $m, 'in');
        }
        $this->asistencia($proximo, $members[5], 'maybe');
        $this->asistencia($proximo, $members[6], 'out');

        // Entrenamiento del martes
        Event::firstOrCreate(
            ['club_id' => $club->id, 'kind' => 'training', 'venue' => 'Baza Sportivă Voluntari — sintético 2'],
            [
                'created_by_member_id' => $manager->id,
                'is_home' => true,
                'starts_at' => now()->next('Tuesday')->setTime(20, 30),
                'kit' => 'home',
                'notes' => 'Llevar botella. El portón se cierra 20:45.',
                'notified_at' => now()->subHours(5),
            ],
        );

        return [$terminado, $proximo];
    }

    protected function votacion(Event $partido, array $members): void
    {
        // Figura: Sergio (0) se lleva 4 votos, Diego (5) 2, Mihai (3) 1
        $votos = [0 => [1, 2, 3, 4], 5 => [0, 6], 3 => [5]];

        foreach ($votos as $votado => $votantes) {
            foreach ($votantes as $votante) {
                MvpVote::updateOrCreate(
                    ['event_id' => $partido->id, 'voter_member_id' => $members[$votante]->id],
                    ['voted_member_id' => $members[$votado]->id],
                );
            }
        }

        // Calificaciones ternarias cruzadas (1 le costó · 2 cumplió · 3 crack)
        $ratings = [
            [1, 0, 3], [2, 0, 3], [3, 0, 3], [4, 0, 2],   // Sergio: casi todos crack
            [0, 5, 3], [1, 5, 2], [6, 5, 2],              // Diego: mixto
            [0, 3, 2], [2, 3, 3],                          // Mihai
            [0, 4, 1], [1, 4, 2],                          // Răzvan tuvo un día difícil
        ];

        foreach ($ratings as [$rater, $rated, $rating]) {
            PlayerRating::updateOrCreate(
                [
                    'event_id' => $partido->id,
                    'rater_member_id' => $members[$rater]->id,
                    'rated_member_id' => $members[$rated]->id,
                ],
                ['rating' => $rating],
            );
        }
    }

    protected function chat(Club $club, array $members, Event $partido): void
    {
        $yaSembrado = Message::withoutGlobalScopes()
            ->where('club_id', $club->id)
            ->where('body', 'like', 'Confirmen para el sábado%')
            ->exists();

        if ($yaSembrado) {
            return;
        }

        $say = fn (Member $m, string $body, array $extra = []) => Message::create([
            'club_id' => $club->id,
            'member_id' => $m->id,
            'body' => $body,
            'is_system' => false,
            ...$extra,
        ]);

        $say($members[0], 'Confirmen para el sábado que la lista se manda el jueves.');

        Message::create([
            'club_id' => $club->id,
            'is_system' => true,
            'body' => json_encode(['key' => 'system.confirmed_match', 'params' => ['name' => 'Diego', 'opponent' => 'CS Afumați II']]),
        ]);

        $say($members[3], 'El martes no llego, tengo turno hasta las 21.');

        // Una foto en el chat: usamos el escudo como imagen de muestra
        $dir = 'vestuario/'.$club->id;
        $path = $dir.'/demo-escudo.png';
        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, file_get_contents(public_path('img/crest.png')));
        }
        $say($members[1], 'Llegaron las camisetas nuevas 🔴', ['attachment_path' => $path]);

        Message::create([
            'club_id' => $club->id,
            'is_system' => true,
            'body' => json_encode(['key' => 'system.mvp_open', 'params' => ['opponent' => $partido->opponent]]),
        ]);
    }

    protected function cuotas(Club $club, array $members): void
    {
        $period = now()->startOfMonth();

        foreach ($club->activeMembers()->pluck('id') as $memberId) {
            Due::firstOrCreate(
                ['club_id' => $club->id, 'member_id' => $memberId, 'period' => $period],
                [
                    'amount_cents' => $club->monthly_fee_cents,
                    'status' => 'pending',
                    'due_date' => $period->copy()->day(20),
                ],
            );
        }

        // Siete al día, el resto debe
        foreach (array_slice($members, 0, 7) as $m) {
            Due::where('club_id', $club->id)
                ->where('member_id', $m->id)
                ->whereDate('period', $period)
                ->update(['status' => 'paid']);
        }
    }

    protected function asistencia(Event $event, Member $member, string $status): void
    {
        Attendance::updateOrCreate(
            ['event_id' => $event->id, 'member_id' => $member->id],
            ['status' => $status, 'responded_at' => now()->subHours(rand(4, 20)), 'source' => rand(0, 1) ? 'app' : 'whatsapp'],
        );
    }
}
