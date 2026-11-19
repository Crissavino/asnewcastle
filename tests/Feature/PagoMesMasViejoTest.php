<?php

use App\Models\Due;
use App\Models\Member;

function cuotaPeriodo(Member $member, string $period, string $status = 'pending', int $cents = 30000): Due
{
    return Due::create([
        'club_id' => $member->club_id,
        'member_id' => $member->id,
        'period' => $period,
        'amount_cents' => $cents,
        'status' => $status,
        'due_date' => $period,
    ]);
}

it('la cuota a pagar es el mes impago más viejo, no el mes en curso', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();

    $viejo = cuotaPeriodo($jugador, now()->copy()->subMonthNoOverflow()->startOfMonth()->toDateString());
    cuotaPeriodo($jugador, now()->startOfMonth()->toDateString());

    $this->actingAs($jugador->user)->get('/cuota')
        ->assertInertia(fn ($page) => $page->where('my_due.id', $viejo->id));
});

it('si está al día muestra la cuota del mes en curso', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();

    cuotaPeriodo($jugador, now()->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'paid');
    $actual = cuotaPeriodo($jugador, now()->startOfMonth()->toDateString(), 'paid');

    $this->actingAs($jugador->user)->get('/cuota')
        ->assertInertia(fn ($page) => $page->where('my_due.id', $actual->id));
});

it('el efectivo sin --periodo salda el mes más viejo', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();
    $jugador->user->update(['name' => 'Hatem Ghrairi']);

    $viejo = cuotaPeriodo($jugador, now()->copy()->subMonthNoOverflow()->startOfMonth()->toDateString());
    $actual = cuotaPeriodo($jugador, now()->startOfMonth()->toDateString());

    $this->artisan('app:cuota-efectivo', ['nombre' => 'Hatem', 'lei' => 300, 'modo' => 'go'])
        ->assertSuccessful();

    expect($viejo->fresh()->status)->toBe('paid')
        ->and($actual->fresh()->status)->toBe('pending');
});

it('cuotaMasVieja ignora las pagadas y las condonadas', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();

    cuotaPeriodo($jugador, '2026-08-01', 'paid');
    cuotaPeriodo($jugador, '2026-09-01', 'waived');
    $debida = cuotaPeriodo($jugador, '2026-10-01', 'pending');

    expect($jugador->cuotaMasVieja()->id)->toBe($debida->id);
});
