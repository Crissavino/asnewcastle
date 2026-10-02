<?php

use App\Http\Controllers\Auth\InviteController;
use App\Models\Club;
use App\Models\Member;
use App\Models\User;

/**
 * La baja sola no alcanzaba: con un club único, el que no tenía membresía
 * activa se auto-sumaba en el login y se borraba su propio left_at. El
 * bloqueo cierra las dos puertas, porque las dos pasan por joinOrRejoin.
 */
it('sin bloqueo, el que se fue vuelve solo (el agujero que teníamos)', function () {
    $member = Member::factory()->create(['left_at' => now()->subDay()]);

    InviteController::joinOrRejoin($member->user, $member->club_id);

    expect($member->fresh()->left_at)->toBeNull();
});

it('el bloqueado no vuelve ni con el link de invitación', function () {
    $member = Member::factory()->create([
        'left_at' => now()->subDay(),
        'blocked_at' => now(),
    ]);

    InviteController::joinOrRejoin($member->user, $member->club_id, 'player');

    expect($member->fresh()->left_at)->not->toBeNull();
});

/** Login real por OTP con el código maestro, como lo haría el jugador. */
function loguearConMaestro(object $test, string $phone): void
{
    config(['services.otp.master_code' => '1234567']);

    $test->withSession(['otp_phone' => $phone])
        ->post(route('otp.verificar'), ['code' => '1234567']);
}

it('sin bloqueo, el login con club único lo reactiva (control del test de abajo)', function () {
    $member = Member::factory()->create(['left_at' => now()->subDay()]);

    expect(Club::count())->toBe(1);

    loguearConMaestro($this, $member->user->phone);

    expect($member->fresh()->left_at)->toBeNull();
});

it('el bloqueado no vuelve por el auto-join del login con club único', function () {
    $member = Member::factory()->create([
        'left_at' => now()->subDay(),
        'blocked_at' => now(),
    ]);

    expect(Club::count())->toBe(1);

    loguearConMaestro($this, $member->user->phone);

    expect($member->fresh()->left_at)->not->toBeNull()
        ->and($member->user->fresh()->activeMembers()->count())->toBe(0);
});

it('desbloqueado, vuelve a entrar normalmente', function () {
    $member = Member::factory()->create([
        'left_at' => now()->subDay(),
        'blocked_at' => now(),
    ]);

    $member->update(['blocked_at' => null]);
    InviteController::joinOrRejoin($member->user, $member->club_id);

    expect($member->fresh()->left_at)->toBeNull();
});

it('el bloqueo es del club: en otro club la persona entra igual', function () {
    $user = User::factory()->create(['phone_verified_at' => now()]);

    $bloqueado = Member::factory()->create([
        'user_id' => $user->id,
        'left_at' => now(),
        'blocked_at' => now(),
    ]);
    $otroClub = Club::factory()->create();

    InviteController::joinOrRejoin($user, $otroClub->id);

    expect($bloqueado->fresh()->left_at)->not->toBeNull()
        ->and($user->activeMembers()->pluck('club_id')->all())->toBe([$otroClub->id]);
});

it('el comando bloquea y desbloquea, y en dry no toca nada', function () {
    $member = Member::factory()->create(['left_at' => now()]);
    $nombre = $member->user->name;

    $this->artisan('app:bloquear', ['quien' => $nombre])->assertSuccessful();
    expect($member->fresh()->blocked_at)->toBeNull();

    $this->artisan('app:bloquear', ['quien' => $nombre, 'modo' => 'go'])->assertSuccessful();
    expect($member->fresh()->blocked_at)->not->toBeNull();

    $this->artisan('app:bloquear', ['quien' => $nombre, 'modo' => 'go', '--abrir' => true])->assertSuccessful();
    expect($member->fresh()->blocked_at)->toBeNull();
});
