<?php

use App\Models\Club;
use App\Models\Due;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    $this->app->instance(WhatsAppChannel::class, new FakeWhatsAppChannel());
});

it('el técnico va aparte del plantel (staff) y no recibe cuota', function () {
    $manager = Member::factory()->manager()->create();
    Member::factory()->for($manager->club)->create(); // jugador
    $coach = Member::factory()->coach()->for($manager->club)->create();

    // No figura entre los jugadores; sí en el cuerpo técnico
    $this->actingAs($manager->user)
        ->get('/perfil')
        ->assertInertia(fn (Assert $p) => $p
            ->has('roster', 2)   // manager + jugador
            ->has('staff', 1)    // el técnico
        );

    // No se le genera cuota (es staff, no juega)
    $this->artisan('cuotas:generar')->assertSuccessful();
    expect(Due::withoutGlobalScopes()->where('member_id', $coach->id)->count())->toBe(0);
});

it('el alta del técnico es solo el nombre', function () {
    $coach = Member::factory()->coach()->create();
    $coach->user->update(['name' => null]);

    // Sin nombre, lo manda al wizard
    $this->actingAs($coach->user)->get('/agenda')->assertRedirect(route('alta'));

    // Completa con solo el nombre (sin dorsal/puesto/etc.) y entra
    $this->actingAs($coach->user)
        ->post('/alta', ['first_name' => 'jose', 'last_name' => 'pekerman'])
        ->assertRedirect(route('agenda'));

    $coach->refresh();
    expect($coach->user->name)->toBe('Jose Pekerman')
        ->and($coach->profileComplete())->toBeTrue();
});

it('el técnico ve las estadísticas de cualquier jugador', function () {
    $coach = Member::factory()->coach()->create();
    $player = Member::factory()->for($coach->club)->create();

    $this->actingAs($coach->user)
        ->get(route('plantel.estadisticas', $player->id))
        ->assertOk();
});

it('el técnico no puede crear eventos', function () {
    $coach = Member::factory()->coach()->create();

    $this->actingAs($coach->user)
        ->post('/eventos', [])
        ->assertForbidden();

    expect(Event::withoutGlobalScopes()->count())->toBe(0);
});

it('un link de invitación con rol coach crea al técnico', function () {
    $club = Club::factory()->create();
    $user = User::factory()->create(['phone_verified_at' => now()]);

    $url = URL::temporarySignedRoute('invitacion', now()->addDay(), [
        'club' => $club->slug,
        'role' => 'coach',
    ]);

    $this->actingAs($user)->get($url)->assertRedirect(route('agenda'));

    $member = $user->members()->where('club_id', $club->id)->first();
    expect($member)->not->toBeNull()
        ->and($member->role)->toBe('coach');
});
