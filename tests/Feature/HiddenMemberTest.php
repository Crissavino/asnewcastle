<?php

use App\Models\Due;
use App\Models\Member;
use App\Services\WhatsApp\WhatsAppChannel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    $this->app->instance(WhatsAppChannel::class, new FakeWhatsAppChannel());
});

it('un miembro oculto no aparece en el plantel pero conserva su acceso', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $hidden = Member::factory()->for($manager->club)->create(['hidden' => true]);

    // El manager ve manager + player, nunca al oculto
    $this->actingAs($manager->user)
        ->get('/perfil')
        ->assertInertia(fn (Assert $page) => $page->has('roster', 2));

    // El oculto entra igual (no lo mandan a "sin club")
    $this->actingAs($hidden->user)->get('/agenda')->assertOk();
});

it('un miembro que se logueó pero no completó el alta no aparece en el plantel', function () {
    $manager = Member::factory()->manager()->create();
    Member::factory()->for($manager->club)->create(); // completo
    Member::factory()->incomplete()->for($manager->club)->create(); // sin dorsal (a medias)

    // El manager ve manager + el completo, nunca al que quedó a medias
    $this->actingAs($manager->user)
        ->get('/perfil')
        ->assertInertia(fn (Assert $page) => $page->has('roster', 2));
});

it('las cuotas mensuales no incluyen al miembro oculto', function () {
    $manager = Member::factory()->manager()->create();
    Member::factory()->for($manager->club)->create();
    $hidden = Member::factory()->for($manager->club)->create(['hidden' => true]);

    $this->artisan('cuotas:generar')->assertSuccessful();

    expect(Due::withoutGlobalScopes()->where('member_id', $hidden->id)->count())->toBe(0);
});
