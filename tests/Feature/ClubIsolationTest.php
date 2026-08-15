<?php

use App\Models\Club;
use App\Models\Member;
use Inertia\Testing\AssertableInertia as Assert;

it('resuelve el club del usuario y lo comparte a la página', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('club.id', $member->club_id)
            ->where('member.role', 'player')
        );
});

it('no deja activar un club del que el usuario no es member', function () {
    $member = Member::factory()->create();
    $otherClub = Club::factory()->create();

    // Aunque la sesión traiga un club ajeno, el middleware cae al club propio
    $this->actingAs($member->user)
        ->withSession(['active_club_id' => $otherClub->id])
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('club.id', $member->club_id)
        );
});

it('un member dado de baja no entra al club', function () {
    $member = Member::factory()->create(['left_at' => now()]);

    $this->actingAs($member->user)->get('/agenda')->assertRedirect(route('sin-club'));
});
