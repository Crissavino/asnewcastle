<?php

use App\Models\Member;
use App\Models\User;

it('app:lesion marca y desmarca por nombre, con dry por defecto', function () {
    $m = Member::factory()->create();
    $m->user->update(['name' => 'Joeri Van Test']);

    // Dry: no toca nada
    $this->artisan('app:lesion', ['quien' => 'Joeri'])->assertSuccessful();
    expect($m->fresh()->isInjured())->toBeFalse();

    // Go: lesionado
    $this->artisan('app:lesion', ['quien' => 'Joeri', 'modo' => 'go'])->assertSuccessful();
    expect($m->fresh()->isInjured())->toBeTrue();

    // --sano lo recupera
    $this->artisan('app:lesion', ['quien' => 'Joeri', 'modo' => 'go', '--sano' => true])->assertSuccessful();
    expect($m->fresh()->isInjured())->toBeFalse();
});

it('app:lesion no aplica con nombre ambiguo o inexistente', function () {
    $a = Member::factory()->create();
    $a->user->update(['name' => 'Robert Burtea']);
    $b = Member::factory()->create();
    $b->user->update(['name' => 'Robert Petrescu']);

    $this->artisan('app:lesion', ['quien' => 'Robert', 'modo' => 'go'])->assertFailed();
    expect($a->fresh()->isInjured())->toBeFalse()
        ->and($b->fresh()->isInjured())->toBeFalse();

    $this->artisan('app:lesion', ['quien' => 'Burtea', 'modo' => 'go'])->assertSuccessful();
    expect($a->fresh()->isInjured())->toBeTrue();

    $this->artisan('app:lesion', ['quien' => 'NoExiste', 'modo' => 'go'])->assertFailed();
});
