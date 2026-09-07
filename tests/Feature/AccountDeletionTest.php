<?php

use App\Models\Due;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Message;
use App\Models\User;

it('elimina la cuenta y todos los datos personales del usuario', function () {
    $member = Member::factory()->manager()->create();
    $user = $member->user;
    $club = $member->club;

    // Datos del usuario (los suyos se borran; los del club sobreviven sin autor)
    $event = Event::factory()->create(['club_id' => $club->id, 'created_by_member_id' => $member->id]);
    $due = Due::factory()->create(['club_id' => $club->id, 'member_id' => $member->id]);
    $expense = Expense::create([
        'club_id' => $club->id, 'member_id' => $member->id,
        'category' => 'referee', 'amount_cents' => 5000, 'spent_on' => now(),
    ]);
    $message = Message::create([
        'club_id' => $club->id, 'member_id' => $member->id, 'body' => 'hola equipo', 'is_system' => false,
    ]);

    $this->actingAs($user)->delete('/cuenta')->assertRedirect(route('entrar'));

    // Cuenta y datos personales: borrados
    expect(User::find($user->id))->toBeNull()
        ->and(Member::find($member->id))->toBeNull()
        ->and(Due::find($due->id))->toBeNull();

    // Registros del club: sobreviven, sin la atribución del autor
    expect($event->fresh()->created_by_member_id)->toBeNull()
        ->and($expense->fresh()->member_id)->toBeNull()
        ->and($message->fresh()->member_id)->toBeNull();

    $this->assertGuest();
});

it('un invitado no puede eliminar una cuenta', function () {
    $this->delete('/cuenta')->assertRedirect(route('entrar'));
});
