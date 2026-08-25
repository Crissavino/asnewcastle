<?php

use App\Models\Club;
use App\Models\Member;
use App\Models\User;
use App\Services\Otp\OtpChannel;
use Tests\Support\FakeOtpChannel;

beforeEach(function () {
    $this->channel = new FakeOtpChannel();
    $this->app->instance(OtpChannel::class, $this->channel);
});

it('el manager genera un link de invitación firmado', function () {
    $manager = Member::factory()->manager()->create();

    $response = $this->actingAs($manager->user)->post('/invitaciones');

    $response->assertSessionHas('invite_url');
    expect(session('invite_url'))
        ->toContain('/invitacion/'.$manager->club->slug)
        ->toContain('signature=');
});

it('un jugador no puede generar invitaciones', function () {
    $player = Member::factory()->create();

    $this->actingAs($player->user)->post('/invitaciones')->assertForbidden();
});

it('un link adulterado no sirve', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->post('/invitaciones');
    $url = str_replace('signature=', 'signature=x', session('invite_url'));

    $invited = User::factory()->create();
    $this->actingAs($invited)->get($url)->assertForbidden();
});

it('un link vencido no sirve', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->post('/invitaciones');
    $url = session('invite_url');

    $this->travel(8)->days();

    $invited = User::factory()->create();
    $this->actingAs($invited)->get($url)->assertForbidden();
});

it('un usuario logueado que abre el link queda asociado al club', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->post('/invitaciones');
    $url = session('invite_url');

    $invited = User::factory()->create();
    $this->actingAs($invited)->get($url)->assertRedirect(route('agenda'));

    expect(
        Member::where('user_id', $invited->id)
            ->where('club_id', $manager->club_id)
            ->where('role', 'player')
            ->exists()
    )->toBeTrue();
});

it('un invitado nuevo queda asociado al club después de verificar el OTP', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->post('/invitaciones');
    $url = session('invite_url');

    // El invitado abre el link sin estar logueado: ve la guía de descarga y
    // el club queda recordado en la sesión para asociarlo al loguear.
    $this->post('/salir');
    $this->get($url)
        ->assertInertia(fn ($page) => $page->component('Auth/Sumate'))
        ->assertSessionHas('invite_club_id', $manager->club_id);

    $this->post('/otp', ['phone' => '+40787654321']);
    $code = $this->channel->lastCodeFor('+40787654321');
    $this->post('/codigo', ['code' => $code])->assertRedirect(route('agenda'));

    $user = User::where('phone', '+40787654321')->first();
    expect(
        Member::where('user_id', $user->id)
            ->where('club_id', $manager->club_id)
            ->exists()
    )->toBeTrue();
});
