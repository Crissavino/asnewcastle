<?php

use App\Models\AuditLog;
use App\Models\Due;
use App\Models\Member;
use App\Models\Payment;

function cuotaDe(Member $member, string $period, string $status = 'pending', int $cents = 30000): Due
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

it('mueve el pago al mes viejo y deja el mes nuevo pendiente', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();

    $viejo = cuotaDe($jugador, '2026-10-01', 'pending');
    $nuevo = cuotaDe($jugador, '2026-11-01', 'paid');
    $pago = Payment::create([
        'due_id' => $nuevo->id,
        'provider' => 'mollie',
        'mollie_payment_id' => 'tr_test_1',
        'amount_cents' => 30000,
        'application_fee_cents' => 0,
        'status' => 'succeeded',
        'paid_at' => now(),
    ]);

    $this->artisan('app:mover-pago', ['pago' => $pago->id, 'due' => $viejo->id, 'modo' => 'aplicar'])
        ->assertSuccessful();

    expect($pago->fresh()->due_id)->toBe($viejo->id)
        ->and($viejo->fresh()->status)->toBe('paid')
        ->and($nuevo->fresh()->status)->toBe('pending');

    expect(AuditLog::withoutGlobalScopes()->where('action', 'due.status.set')->count())->toBe(2);
});

it('el dry run no toca nada', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();
    $viejo = cuotaDe($jugador, '2026-10-01', 'pending');
    $nuevo = cuotaDe($jugador, '2026-11-01', 'paid');
    $pago = Payment::create([
        'due_id' => $nuevo->id, 'provider' => 'mollie', 'mollie_payment_id' => 'tr_test_2',
        'amount_cents' => 30000, 'application_fee_cents' => 0, 'status' => 'succeeded', 'paid_at' => now(),
    ]);

    $this->artisan('app:mover-pago', ['pago' => $pago->id, 'due' => $viejo->id])->assertSuccessful();

    expect($pago->fresh()->due_id)->toBe($nuevo->id)
        ->and($viejo->fresh()->status)->toBe('pending');
});

it('no mueve un pago a la cuota de otro jugador', function () {
    $manager = Member::factory()->manager()->create();
    $uno = Member::factory()->for($manager->club)->create();
    $otro = Member::factory()->for($manager->club)->create();

    $suya = cuotaDe($uno, '2026-11-01', 'paid');
    $ajena = cuotaDe($otro, '2026-10-01', 'pending');
    $pago = Payment::create([
        'due_id' => $suya->id, 'provider' => 'mollie', 'mollie_payment_id' => 'tr_test_3',
        'amount_cents' => 30000, 'application_fee_cents' => 0, 'status' => 'succeeded', 'paid_at' => now(),
    ]);

    $this->artisan('app:mover-pago', ['pago' => $pago->id, 'due' => $ajena->id, 'modo' => 'aplicar'])
        ->assertFailed();

    expect($ajena->fresh()->status)->toBe('pending');
});

it('becar condona lo pendiente y no toca lo pagado', function () {
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create();
    $jugador->user->update(['name' => 'Ahmed Ben Jazia']);

    $pagada = cuotaDe($jugador, '2026-09-01', 'paid');
    $debida = cuotaDe($jugador, '2026-10-01', 'pending');

    $this->artisan('app:becar', ['nombre' => 'Ahmed', 'modo' => 'aplicar'])->assertSuccessful();

    expect($jugador->fresh()->fee_type)->toBe('becado')
        ->and($debida->fresh()->status)->toBe('waived')
        ->and($pagada->fresh()->status)->toBe('paid');
});
