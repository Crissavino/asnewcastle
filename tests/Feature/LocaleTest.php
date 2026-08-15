<?php

use App\Models\Member;
use Inertia\Testing\AssertableInertia as Assert;

it('detecta el idioma del teléfono en la primera visita', function () {
    $this->withHeaders(['Accept-Language' => 'ro-RO,ro;q=0.9,en;q=0.8'])
        ->get('/entrar')
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ro')
            ->where('messages', fn ($messages) => collect($messages)->get('auth.phone_title') === 'Intră cu telefonul tău')
        );
});

it('cae en inglés si el idioma del teléfono no está soportado', function () {
    $this->withHeaders(['Accept-Language' => 'de-DE,de;q=0.9'])
        ->get('/entrar')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('guarda el idioma elegido en el usuario logueado', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)->post('/idioma', ['locale' => 'es']);

    expect($member->user->fresh()->locale)->toBe('es');

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'es'));
});

it('rechaza idiomas que no existen', function () {
    $this->post('/idioma', ['locale' => 'fr'])->assertSessionHasErrors('locale');
});
