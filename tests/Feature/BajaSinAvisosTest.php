<?php

use App\Models\DeviceToken;
use App\Models\Member;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications;
use App\Services\Push\Notifier;
use App\Services\Push\PushSender;

/**
 * Al que dieron de baja del plantel (left_at) no le llega nada: ni campanita
 * ni push. Se filtra en los dos embudos —Notifications::deliver y
 * Notifier::byLocale— y no comando por comando, para que lo que agreguemos
 * después tampoco le escriba a un ex-jugador.
 */

/** PushSender que anota los tokens a los que se mandó. */
function spyPush(): object
{
    $sender = new class implements PushSender
    {
        public array $enviados = [];

        public function send(array $tokens, string $title, string $body, array $data = []): array
        {
            $this->enviados = array_merge($this->enviados, $tokens);

            return [];
        }
    };

    app()->instance(PushSender::class, $sender);

    return $sender;
}

it('la campanita no le llega al que se fue del club', function () {
    $activo = Member::factory()->create();
    $baja = Member::factory()->create([
        'club_id' => $activo->club_id,
        'left_at' => now()->subDay(),
    ]);

    app(Notifications::class)->deliver(
        $activo->club_id,
        [$activo->id, $baja->id],
        'dues',
        'notifications.dues_due',
    );

    expect(Notification::where('member_id', $activo->id)->count())->toBe(1)
        ->and(Notification::where('member_id', $baja->id)->count())->toBe(0);
});

it('la push no le llega al que se fue del club', function () {
    $sender = spyPush();

    $activo = Member::factory()->create();
    $baja = Member::factory()->create([
        'club_id' => $activo->club_id,
        'left_at' => now()->subDay(),
    ]);

    DeviceToken::create(['user_id' => $activo->user_id, 'token' => 'tok_activo', 'platform' => 'ios']);
    DeviceToken::create(['user_id' => $baja->user_id, 'token' => 'tok_baja', 'platform' => 'ios']);

    app(Notifier::class)->dues(Member::with('user')->whereIn('id', [$activo->id, $baja->id])->get());

    expect($sender->enviados)->toContain('tok_activo')
        ->and($sender->enviados)->not->toContain('tok_baja');
});

it('si todos los destinatarios se fueron, no se escribe ninguna fila', function () {
    $baja = Member::factory()->create(['left_at' => now()]);

    app(Notifications::class)->deliver($baja->club_id, [$baja->id], 'dues', 'notifications.dues_due');

    expect(Notification::count())->toBe(0);
});

it('el que se fue de un club entra igual por el otro donde sigue activo', function () {
    $activo = Member::factory()->create();
    $baja = Member::factory()->create([
        'user_id' => $activo->user_id,
        'left_at' => now(),
    ]);

    expect($baja->club_id)->not->toBe($activo->club_id)
        ->and($activo->user->activeMembers()->pluck('id')->all())->toBe([$activo->id]);

    $this->actingAs($activo->user)->get('/agenda')->assertOk();
});

it('el que no le queda ningún club cae en la pantalla de baja, no en la de bienvenida', function () {
    $baja = Member::factory()->create(['left_at' => now()]);

    $this->actingAs($baja->user)->get('/agenda')->assertRedirect(route('sin-club'));

    $this->actingAs($baja->user)->get(route('sin-club'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/SinClub')->where('removed', true));
});

it('el que nunca estuvo en un club ve el texto de bienvenida', function () {
    $user = User::factory()->create(['phone_verified_at' => now()]);

    $this->actingAs($user)->get(route('sin-club'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('removed', false));
});
