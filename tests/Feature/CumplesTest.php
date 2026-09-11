<?php

use App\Models\Member;
use App\Models\Message;

it('cumples:anunciar publica el cumpleaños del día solo en el club del cumpleañero', function () {
    $cumpleanero = Member::factory()->create();
    $cumpleanero->user->update(['birth_date' => today()->subYears(28)]);

    $otroDia = Member::factory()->for($cumpleanero->club)->create();
    $otroDia->user->update(['birth_date' => today()->subYears(30)->subDays(40)]);

    $otroClub = Member::factory()->create(); // club ajeno, sin cumpleaños

    $this->artisan('cumples:anunciar')->assertSuccessful();

    $mensajes = Message::withoutGlobalScopes()->where('is_system', true)->get();
    expect($mensajes)->toHaveCount(1)
        ->and($mensajes->first()->club_id)->toBe($cumpleanero->club_id);

    $body = json_decode($mensajes->first()->body, true);
    expect($body['key'])->toBe('system.birthday')
        ->and($body['params']['name'])->toBe($cumpleanero->user->name)
        ->and($body['params']['age'])->toBe(28);
});

it('cumples:anunciar no publica nada si nadie cumple o falta la fecha', function () {
    Member::factory()->create(); // sin birth_date

    $this->artisan('cumples:anunciar')->assertSuccessful();

    expect(Message::withoutGlobalScopes()->count())->toBe(0);
});
