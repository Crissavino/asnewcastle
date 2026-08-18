<?php

use App\Models\Club;
use App\Models\Member;
use App\Models\User;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpManager;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeOtpChannel;

beforeEach(function () {
    $this->channel = new FakeOtpChannel();
    $this->app->instance(OtpChannel::class, $this->channel);
    RateLimiter::clear('otp-send:+40712345678');
});

it('manda el código por el canal y pasa a la pantalla de código', function () {
    $response = $this->post('/otp', ['phone' => '+40712345678']);

    $response->assertRedirect(route('codigo'));
    expect($this->channel->sent)->toHaveCount(1)
        ->and($this->channel->sent[0]['phone'])->toBe('+40712345678')
        ->and($this->channel->sent[0]['code'])->toMatch('/^\d{6}$/');
});

it('rechaza números de países no soportados', function () {
    $this->post('/otp', ['phone' => '+1 555 234 5678'])
        ->assertSessionHasErrors('phone');

    expect($this->channel->sent)->toBeEmpty();
});

it('corta al cuarto código en una hora', function () {
    foreach (range(1, 3) as $i) {
        $this->post('/otp', ['phone' => '+40712345678'])->assertSessionHasNoErrors();
    }

    $this->post('/otp', ['phone' => '+40712345678'])->assertSessionHasErrors('phone');
    expect($this->channel->sent)->toHaveCount(3);
});

it('con el código correcto crea el usuario, verifica el teléfono y loguea', function () {
    Club::factory()->create(['slug' => 'as-new-castle']);

    $this->post('/otp', ['phone' => '+40712345678']);
    $code = $this->channel->lastCodeFor('+40712345678');

    $this->post('/codigo', ['code' => $code])->assertRedirect(route('agenda'));

    $user = User::where('phone', '+40712345678')->first();
    expect($user)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('no loguea con un código incorrecto', function () {
    $this->post('/otp', ['phone' => '+40712345678']);

    $this->post('/codigo', ['code' => '000000'])->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(User::where('phone', '+40712345678')->exists())->toBeFalse();
});

it('el código es de un solo uso', function () {
    $manager = app(OtpManager::class);
    $manager->send('+40712345678');
    $code = $this->channel->lastCodeFor('+40712345678');

    expect($manager->verify('+40712345678', $code))->toBeTrue()
        ->and($manager->verify('+40712345678', $code))->toBeFalse();
});

it('el código vence a los 10 minutos', function () {
    $manager = app(OtpManager::class);
    $manager->send('+40712345678');
    $code = $this->channel->lastCodeFor('+40712345678');

    $this->travel(11)->minutes();

    expect($manager->verify('+40712345678', $code))->toBeFalse();
});

it('se invalida después de 5 intentos fallidos', function () {
    $manager = app(OtpManager::class);
    $manager->send('+40712345678');
    $code = $this->channel->lastCodeFor('+40712345678');

    foreach (range(1, 5) as $i) {
        expect($manager->verify('+40712345678', '000000'))->toBeFalse();
    }

    // Aunque ahora venga el código correcto, ya no vale
    expect($manager->verify('+40712345678', $code))->toBeFalse();
});

it('el código maestro loguea sin código real cuando está configurado', function () {
    config(['services.otp.master_code' => '1152025']);
    Club::factory()->create(['slug' => 'as-new-castle']);

    $this->post('/otp', ['phone' => '+40712345678']);
    $this->post('/codigo', ['code' => '1152025'])->assertRedirect(route('agenda'));

    $this->assertAuthenticatedAs(User::where('phone', '+40712345678')->first());
});

it('sin OTP_MASTER_CODE configurado no existe ningún bypass', function () {
    config(['services.otp.master_code' => null]);

    $this->post('/otp', ['phone' => '+40712345678']);
    $this->post('/codigo', ['code' => '1152025'])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('un código maestro configurado con menos de 7 dígitos se ignora', function () {
    config(['services.otp.master_code' => '123456']);

    $this->post('/otp', ['phone' => '+40712345678']);
    $this->post('/codigo', ['code' => '123456'])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('el idioma ya elegido en la app manda: el +40 no lo pisa al loguear', function () {
    Club::factory()->create(['slug' => 'as-new-castle']);
    // Número rumano pero eligió español en la app (el caso del presidente)
    $user = User::factory()->create(['phone' => '+40712345678', 'locale' => 'es']);

    $this->post('/otp', ['phone' => '+40712345678']);
    $this->post('/codigo', ['code' => $this->channel->lastCodeFor('+40712345678')]);

    expect($user->fresh()->locale)->toBe('es');
});

it('a un usuario sin idioma elegido le pone el del teléfono al entrar', function () {
    Club::factory()->create(['slug' => 'as-new-castle']);
    // 'en' es el fallback (no una elección real del jugador); +40 → rumano
    $user = User::factory()->create(['phone' => '+40712345678', 'locale' => 'en']);

    $this->post('/otp', ['phone' => '+40712345678']);
    $this->post('/codigo', ['code' => $this->channel->lastCodeFor('+40712345678')]);

    expect($user->fresh()->locale)->toBe('ro');
});

it('un usuario verificado sin club cae en la pantalla sin-club', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/agenda')->assertRedirect(route('sin-club'));
});

it('un usuario con club entra a la agenda', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)->get('/agenda')->assertOk();
});
