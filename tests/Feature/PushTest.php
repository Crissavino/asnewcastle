<?php

use App\Models\DeviceToken;
use App\Models\Member;
use App\Services\Push\PushSender;
use Tests\Support\FakePushSender;
use Tests\Support\FakeWhatsAppChannel;
use App\Services\WhatsApp\WhatsAppChannel;

beforeEach(function () {
    $this->push = new FakePushSender();
    $this->app->instance(PushSender::class, $this->push);
    // La convocatoria también sale por WhatsApp: lo fakeamos para no salir a la red
    $this->app->instance(WhatsAppChannel::class, new FakeWhatsAppChannel());
});

it('registra el token de push del dispositivo', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->postJson('/push/token', ['token' => 'tok-abc', 'platform' => 'android'])
        ->assertJson(['ok' => true]);

    expect(DeviceToken::where('token', 'tok-abc')->first())
        ->user_id->toBe($member->user->id)
        ->platform->toBe('android');
});

it('reasigna un token existente al usuario actual (mismo teléfono, otro user)', function () {
    $a = Member::factory()->create();
    $b = Member::factory()->create();
    DeviceToken::create(['user_id' => $a->user->id, 'token' => 'tok-x', 'platform' => 'ios']);

    $this->actingAs($b->user)->postJson('/push/token', ['token' => 'tok-x', 'platform' => 'ios']);

    expect(DeviceToken::where('token', 'tok-x')->count())->toBe(1)
        ->and(DeviceToken::where('token', 'tok-x')->first()->user_id)->toBe($b->user->id);
});

it('da de baja el token del dispositivo', function () {
    $member = Member::factory()->create();
    DeviceToken::create(['user_id' => $member->user->id, 'token' => 'tok-del', 'platform' => 'web']);

    $this->actingAs($member->user)->deleteJson('/push/token', ['token' => 'tok-del']);

    expect(DeviceToken::where('token', 'tok-del')->exists())->toBeFalse();
});

it('al crear un evento manda push a los dispositivos del plantel, en su idioma', function () {
    $manager = Member::factory()->manager()->create();
    $manager->user->update(['locale' => 'es']);
    $ro = Member::factory()->for($manager->club)->create();
    $ro->user->update(['locale' => 'ro']);

    DeviceToken::create(['user_id' => $manager->user->id, 'token' => 'tok-mgr', 'platform' => 'android']);
    DeviceToken::create(['user_id' => $ro->user->id, 'token' => 'tok-ro', 'platform' => 'ios']);

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'match',
        'opponent' => 'CS Afumați',
        'is_home' => true,
        'starts_at' => now()->addDays(3)->format('Y-m-d H:i'),
        'venue' => 'Teren Voluntari',
        'kit' => 'home',
    ])->assertRedirect();

    // Ambos tokens recibieron push
    expect($this->push->allTokens())->toContain('tok-mgr')->toContain('tok-ro');

    // El título va en el idioma de cada uno (agrupado por locale)
    $titles = collect($this->push->sent)->pluck('title');
    expect($titles)->toContain('⚽ Nuevo partido')  // es
        ->toContain('⚽ Meci nou');                  // ro
});

it('el recordatorio solo va a los dispositivos de los que no respondieron', function () {
    $manager = Member::factory()->manager()->create();
    $event = App\Models\Event::factory()->by($manager)->create();
    $respondio = Member::factory()->for($manager->club)->create();
    $callado = Member::factory()->for($manager->club)->create();

    App\Models\Attendance::create(['event_id' => $event->id, 'member_id' => $respondio->id, 'status' => 'in', 'responded_at' => now()]);

    DeviceToken::create(['user_id' => $respondio->user->id, 'token' => 'tok-si', 'platform' => 'android']);
    DeviceToken::create(['user_id' => $callado->user->id, 'token' => 'tok-no', 'platform' => 'android']);

    $this->actingAs($manager->user)->post("/eventos/{$event->id}/recordar");

    expect($this->push->allTokens())->toContain('tok-no')->not->toContain('tok-si');
});

it('borra los tokens que el proveedor reporta como inválidos', function () {
    $manager = Member::factory()->manager()->create();
    DeviceToken::create(['user_id' => $manager->user->id, 'token' => 'tok-muerto', 'platform' => 'android']);
    $this->push->invalid = ['tok-muerto'];

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'training',
        'is_home' => true,
        'starts_at' => now()->addDays(2)->format('Y-m-d H:i'),
        'venue' => 'Teren Voluntari',
    ])->assertRedirect();

    expect(DeviceToken::where('token', 'tok-muerto')->exists())->toBeFalse();
});
