<?php

use App\Models\Club;
use App\Models\Member;
use App\Models\User;
use App\Services\Otp\OtpChannel;
use App\Services\WhatsApp\WhatsAppChannel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    $this->whatsapp = new FakeWhatsAppChannel();
    $this->app->instance(WhatsAppChannel::class, $this->whatsapp);
});

it('el manager da de baja a un jugador y este desaparece de todo', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();

    $this->actingAs($manager->user)->post("/plantel/{$player->id}/baja")->assertRedirect();

    expect($player->fresh()->left_at)->not->toBeNull();

    // Ya no figura en el plantel
    $this->actingAs($manager->user)
        ->get('/perfil')
        ->assertInertia(fn (Assert $page) => $page->has('roster', 1));

    // Y las nuevas cuotas no lo incluyen
    $this->artisan('cuotas:generar')->assertSuccessful();
    expect(\App\Models\Due::withoutGlobalScopes()->where('member_id', $player->id)->count())->toBe(0);
});

it('nadie se da de baja a sí mismo, ni a otro manager, ni a members ajenos', function () {
    $manager = Member::factory()->manager()->create();
    $otroManager = Member::factory()->manager()->for($manager->club)->create();
    $ajeno = Member::factory()->create();
    $player = Member::factory()->for($manager->club)->create();

    $this->actingAs($manager->user)->post("/plantel/{$manager->id}/baja")->assertForbidden();
    $this->actingAs($manager->user)->post("/plantel/{$otroManager->id}/baja")->assertForbidden();
    $this->actingAs($manager->user)->post("/plantel/{$ajeno->id}/baja")->assertNotFound();
    $this->actingAs($player->user)->post("/plantel/{$manager->id}/baja")->assertForbidden();
});

it('un ex-member que vuelve con invitación recupera su ficha', function () {
    $this->app->instance(OtpChannel::class, new FakeOtpChannel());

    $manager = Member::factory()->manager()->create();
    $exPlayer = Member::factory()->for($manager->club)->create([
        'left_at' => now()->subMonth(),
        'shirt_number' => 9,
    ]);

    $this->actingAs($manager->user)->post('/invitaciones');
    $url = session('invite_url');

    $this->actingAs($exPlayer->user)->get($url)->assertRedirect(route('agenda'));

    $exPlayer->refresh();
    expect($exPlayer->left_at)->toBeNull()
        ->and($exPlayer->shirt_number)->toBe(9); // vuelve con su dorsal
});

it('se edita la ficha desde el perfil, con el dorsal protegido', function () {
    $club = Club::factory()->create();
    $member = Member::factory()->for($club)->create(['shirt_number' => 5]);
    Member::factory()->for($club)->create(['shirt_number' => 10]);

    $this->actingAs($member->user)->patch('/perfil', [
        'name' => 'Cristian M. Savino',
        'position' => 'DEL',
        'preferred_foot' => 'both',
        'shirt_number' => 7,
    ])->assertRedirect();

    $member->refresh();
    expect($member->user->name)->toBe('Cristian M. Savino')
        ->and($member->position)->toBe('DEL')
        ->and($member->shirt_number)->toBe(7);

    // El dorsal tomado se rechaza
    $this->actingAs($member->user)
        ->patch('/perfil', [
            'name' => 'Cristian M. Savino',
            'position' => 'DEL',
            'preferred_foot' => 'both',
            'shirt_number' => 10,
        ])
        ->assertSessionHasErrors('shirt_number');

    // Quedarse con el propio número no molesta
    $this->actingAs($member->user)
        ->patch('/perfil', [
            'name' => 'Cristian M. Savino',
            'position' => 'DEL',
            'preferred_foot' => 'both',
            'shirt_number' => 7,
        ])
        ->assertSessionHasNoErrors();
});

it('el perfil trae las estadísticas de la temporada', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->get('/perfil')
        ->assertInertia(fn (Assert $page) => $page
            ->has('season.matches')
            ->has('season.mvps')
        );
});
