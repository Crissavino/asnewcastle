<?php

use App\Models\AuditLog;
use App\Models\Due;
use App\Models\Member;
use Inertia\Testing\AssertableInertia as Assert;

it('deja registro de quién dejó becado a un jugador', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create(['fee_type' => 'normal']);

    $this->actingAs($manager->user)
        ->post("/plantel/{$player->id}/cuota", ['fee_type' => 'becado'])
        ->assertRedirect();

    $log = AuditLog::withoutGlobalScopes()->where('club_id', $manager->club_id)->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('fee_type.set')
        ->and($log->actor_member_id)->toBe($manager->id)
        ->and($log->subject_type)->toBe(Member::class)
        ->and($log->subject_id)->toBe($player->id)
        ->and($log->meta['from'])->toBe('normal')
        ->and($log->meta['to'])->toBe('becado');
});

it('deja registro de quién marcó a mano una cuota', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $due = Due::factory()->forMember($player)->create(); // pendiente

    $this->actingAs($manager->user)
        ->post("/cuota/{$due->id}/estado", ['status' => 'paid'])
        ->assertRedirect();

    $log = AuditLog::withoutGlobalScopes()
        ->where('club_id', $manager->club_id)
        ->where('action', 'due.status.set')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_member_id)->toBe($manager->id)
        ->and($log->subject_type)->toBe(Due::class)
        ->and($log->subject_id)->toBe($due->id)
        ->and($log->meta['from'])->toBe('pending')
        ->and($log->meta['to'])->toBe('paid');
});

it('el manager ve en configuración quién fijó el tipo y quién marcó la cuota', function () {
    $manager = Member::factory()->manager()->create(['role' => 'manager']);
    $player = Member::factory()->for($manager->club)->create(['fee_type' => 'normal']);
    $due = Due::factory()->forMember($player)->create();

    $this->actingAs($manager->user)->post("/plantel/{$player->id}/cuota", ['fee_type' => 'becado']);
    // Al becar se borra la cuota del mes; genero otra para poder marcarla a mano.
    $due2 = Due::factory()->forMember($player)->create();
    $this->actingAs($manager->user)->post("/cuota/{$due2->id}/estado", ['status' => 'paid']);

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('config.members', fn ($members) => collect($members)->contains(
                fn ($m) => $m['id'] === $player->id
                    && $m['fee_by']['by'] === $manager->user->name
                    && $m['fee_by']['to'] === 'becado'
                    && $m['due_mark']['to'] === 'paid'
                    && $m['due_mark']['by'] === $manager->user->name
            ))
        );
});

it('un jugador que nadie tocó no tiene líneas de auditoría', function () {
    $manager = Member::factory()->manager()->create();
    Member::factory()->for($manager->club)->create(['fee_type' => 'normal']);

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('config.members', fn ($members) => collect($members)->every(
                fn ($m) => $m['fee_by'] === null && $m['due_mark'] === null
            ))
        );
});

it('la auditoría no se filtra entre clubes ni llega al jugador', function () {
    // Club A: se becó a alguien
    $managerA = Member::factory()->manager()->create();
    $playerA = Member::factory()->for($managerA->club)->create(['fee_type' => 'normal']);
    $this->actingAs($managerA->user)->post("/plantel/{$playerA->id}/cuota", ['fee_type' => 'becado']);

    // Club B no ve nada de A (aislamiento por club_id)
    $managerB = Member::factory()->manager()->create();
    Member::factory()->for($managerB->club)->create(['fee_type' => 'normal']);
    $this->actingAs($managerB->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('config.members', fn ($members) => collect($members)->every(fn ($m) => $m['fee_by'] === null))
        );

    // Un jugador nunca recibe 'config' (ni la auditoría que vive adentro)
    $this->actingAs($playerA->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page->missing('config'));
});
