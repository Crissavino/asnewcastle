<?php

use App\Models\Club;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

function altaPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Marius',
        'last_name' => 'Ilie',
        'position' => 'MED',
        'preferred_foot' => 'right',
        'shirt_number' => 21,
        'availability' => ['tue', 'sat'],
    ], $overrides);
}

it('manda al wizard a un member sin alta', function () {
    $member = Member::factory()->incomplete()->create();
    $member->user->update(['name' => null]);

    $this->actingAs($member->user)->get('/agenda')->assertRedirect(route('alta'));
});

it('no deja volver al wizard con el alta hecha', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)->get('/alta')->assertRedirect(route('agenda'));
});

it('completa el alta y entra a la app', function () {
    $member = Member::factory()->incomplete()->create();
    $member->user->update(['name' => null]);

    $this->actingAs($member->user)
        ->post('/alta', altaPayload())
        ->assertRedirect(route('agenda'));

    $member->refresh();
    // Se guarda canónico "Nombre Apellido", así el nombre corto sale bien.
    expect($member->user->name)->toBe('Marius Ilie')
        ->and($member->user->firstName())->toBe('Marius')
        ->and($member->position)->toBe('MED')
        ->and($member->preferred_foot)->toBe('right')
        ->and($member->shirt_number)->toBe(21)
        ->and($member->availability)->toBe(['tue', 'sat']);

    $this->actingAs($member->user)->get('/agenda')->assertOk();
});

it('capitaliza cada palabra del nombre, respetando guiones (rumano)', function () {
    $member = Member::factory()->incomplete()->create();
    $member->user->update(['name' => null]);

    $this->actingAs($member->user)
        ->post('/alta', altaPayload(['first_name' => 'adam', 'last_name' => 'burtea-ruprich']))
        ->assertRedirect(route('agenda'));

    expect($member->fresh()->user->name)->toBe('Adam Burtea-Ruprich');
});

it('muestra tachados solo los dorsales del club activo', function () {
    $club = Club::factory()->create();
    $taken = Member::factory()->for($club)->create(['shirt_number' => 10]);
    Member::factory()->create(['shirt_number' => 7]); // otro club

    $nuevo = Member::factory()->incomplete()->for($club)->create();

    $this->actingAs($nuevo->user)
        ->get('/alta')
        ->assertInertia(fn (Assert $page) => $page
            ->where('taken', [10])
        );
});

it('rechaza un dorsal ya tomado en el club', function () {
    $club = Club::factory()->create();
    Member::factory()->for($club)->create(['shirt_number' => 10]);
    $nuevo = Member::factory()->incomplete()->for($club)->create();

    $this->actingAs($nuevo->user)
        ->post('/alta', altaPayload(['shirt_number' => 10]))
        ->assertSessionHasErrors('shirt_number');

    expect($nuevo->fresh()->shirt_number)->toBeNull();
});

it('permite el mismo dorsal en clubes distintos', function () {
    Member::factory()->create(['shirt_number' => 10]);

    $otro = Member::factory()->incomplete()->create();
    $this->actingAs($otro->user)
        ->post('/alta', altaPayload(['shirt_number' => 10]))
        ->assertRedirect(route('agenda'));

    expect($otro->fresh()->shirt_number)->toBe(10);
});

it('la base corta una carrera por el mismo dorsal', function () {
    $club = Club::factory()->create();
    Member::factory()->for($club)->create(['shirt_number' => 10]);

    // Simula la segunda request de la carrera: pasó la validación
    // (lista vieja de tomados) y choca contra el unique de la DB.
    $perdedor = Member::factory()->incomplete()->for($club)->create();

    expect(fn () => $perdedor->update(['shirt_number' => 10]))
        ->toThrow(QueryException::class);
});

it('valida puesto, perfil y disponibilidad contra las listas cerradas', function () {
    $member = Member::factory()->incomplete()->create();

    $this->actingAs($member->user)
        ->post('/alta', altaPayload([
            'position' => 'XXX',
            'preferred_foot' => 'zurdo-total',
            'availability' => ['lunes-a-la-nochecita'],
        ]))
        ->assertSessionHasErrors(['position', 'preferred_foot', 'availability.0']);
});

it('la disponibilidad se edita desde el perfil, solo con días válidos', function () {
    $member = Member::factory()->create(['availability' => ['tue', 'sat']]);

    $this->actingAs($member->user)
        ->post('/perfil/disponibilidad', ['availability' => ['mon', 'wed', 'sun']])
        ->assertRedirect();

    expect($member->fresh()->availability)->toBe(['mon', 'wed', 'sun']);

    $this->actingAs($member->user)
        ->post('/perfil/disponibilidad', ['availability' => []])
        ->assertSessionHasErrors('availability');

    $this->actingAs($member->user)
        ->post('/perfil/disponibilidad', ['availability' => ['feriados-nomas']])
        ->assertSessionHasErrors('availability.0');

    expect($member->fresh()->availability)->toBe(['mon', 'wed', 'sun']);
});

it('el perfil muestra el plantel del club sin teléfonos', function () {
    $club = Club::factory()->create();
    $member = Member::factory()->for($club)->create(['shirt_number' => 10]);
    Member::factory()->for($club)->create(['shirt_number' => 5]);

    $this->actingAs($member->user)
        ->get('/perfil')
        ->assertInertia(fn (Assert $page) => $page
            ->has('roster', 2)
            ->where('me.shirt_number', 10)
            ->missing('roster.0.phone')
            ->missing('roster.1.phone')
        );
});
