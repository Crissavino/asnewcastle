<?php

use App\Models\AuditLog;
use App\Models\Due;
use App\Models\Member;

function cuotaDelMes(Member $member, int $cents = 20000): Due
{
    return Due::create([
        'club_id' => $member->club_id,
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount_cents' => $cents,
        'status' => 'pending',
        'due_date' => now()->endOfMonth(),
    ]);
}

it('marca la cuota del mes pagada por el monto cargado y deja rastro', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();
    $jugador->user->update(['name' => 'Adam Loz']);
    $due = cuotaDelMes($jugador);

    $this->artisan('app:cuota-efectivo', ['nombre' => 'Adam', 'lei' => 150, 'modo' => 'go'])
        ->assertSuccessful();

    $due->refresh();
    expect($due->status)->toBe('paid')
        ->and($due->amount_cents)->toBe(15000);

    $log = AuditLog::withoutGlobalScopes()->latest('id')->first();
    expect($log->action)->toBe('due.status.set')
        ->and($log->actor_member_id)->toBe($manager->id)
        ->and($log->meta['amount_cents_from'])->toBe(20000)
        ->and($log->meta['amount_cents'])->toBe(15000);

    // El tipo de cuota no se toca: el mes que viene paga lo normal
    expect($jugador->fresh()->fee_type)->toBe('normal');
});

it('el dry run no cambia nada', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();
    $jugador->user->update(['name' => 'Adam Loz']);
    $due = cuotaDelMes($jugador);

    $this->artisan('app:cuota-efectivo', ['nombre' => 'Adam', 'lei' => 150])
        ->assertSuccessful();

    expect($due->fresh()->status)->toBe('pending')
        ->and($due->fresh()->amount_cents)->toBe(20000)
        ->and(AuditLog::withoutGlobalScopes()->count())->toBe(0);
});

it('no aplica si el nombre no identifica a un solo jugador', function () {
    $manager = Member::factory()->manager()->create();
    foreach (['Adam Loz', 'Adam Otro'] as $nombre) {
        $m = Member::factory()->for($manager->club)->create();
        $m->user->update(['name' => $nombre]);
        cuotaDelMes($m);
    }

    $this->artisan('app:cuota-efectivo', ['nombre' => 'Adam', 'lei' => 150, 'modo' => 'go'])
        ->assertFailed();

    expect(Due::withoutGlobalScopes()->where('status', 'paid')->count())->toBe(0);
});

it('no toca una cuota pagada por Stripe', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();
    $jugador->user->update(['name' => 'Adam Loz']);
    $due = cuotaDelMes($jugador);
    $due->payments()->create([
        'stripe_payment_intent_id' => 'pi_test_'.uniqid(),
        'amount_cents' => 20000,
        'application_fee_cents' => 0,
        'status' => 'succeeded',
        'paid_at' => now(),
    ]);

    $this->artisan('app:cuota-efectivo', ['nombre' => 'Adam', 'lei' => 150, 'modo' => 'go'])
        ->assertFailed();

    expect($due->fresh()->amount_cents)->toBe(20000);
});
