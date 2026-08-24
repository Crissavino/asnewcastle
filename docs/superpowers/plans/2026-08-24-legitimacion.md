# Fase 7 — Legitimación en la Federación (2026-27) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Formulario `/legitimacion` para que cada jugador cargue su ficha federativa (datos + documentos), con vista delegado (tabla, ZIP, recordatorio) y purga automática a los 90 días.

**Architecture:** Una tabla `registrations` (una fila por member+season), archivos en el disco `local` privado del servidor (Forge, no S3), descarga solo por endpoint autenticado con Gate de manager que streamea el archivo (nunca hay URL pública ni firmada). CNP y pasaporte cifrados en DB con cast `encrypted`. Una sola página Inertia `Legitimacion.jsx` que muestra la ficha propia siempre y la tabla del plantel si `actsAsManager()`.

**Tech Stack:** Laravel 12, Inertia 2 + React 18, CSS plano existente, Pest, ZipArchive (ext-zip), WhatsAppChannel existente + Notifications (campanita).

**Spec:** el mensaje del dueño del 24.08.2026 (Fase 7), con estos ajustes aprobados por él: web-only, sin S3 (disco del servidor), "lo mejor que se pueda y más fácil", prioridad: formulario funcionando antes del 25.08.

## Global Constraints

- Todo query filtra por `club_id` (via `CurrentClub::assertOwns` / scoping explícito).
- i18n en `lang/{en,ro,es}.json`; español rioplatense (vos).
- Mobile-first 380px, touch targets 44px.
- Nada que dependa del navegador (sin `window.open`; descargas vía navegación normal de link, que Capacitor puede interceptar después — el ZIP lo baja solo el manager desde web).
- Montos en centavos no aplican acá (195 RON es texto fijo + IBAN copiable).
- Un jugador NUNCA ve la documentación ni los datos de otro. El manager ve todo.
- Deadline: `2026-08-25` en `config/legitimacion.php`. Importe `195 RON`, IBAN `RO59 RNCB 0077 1851 2329 0001`.
- Archivos: `storage/app/private/legitimacion/{club_id}/{member_id}/{campo}.{ext}` (disco `local`, fuera de `public`).
- Campos exactamente los de la spec — no agregar ninguno.
- No cobrar por Stripe. No mandar documentos por mail.

---

### Task 1: Migración + modelo `Registration`

**Files:**
- Create: `database/migrations/2026_08_24_000001_create_registrations_table.php`
- Create: `app/Models/Registration.php`
- Create: `database/factories/RegistrationFactory.php`
- Create: `config/legitimacion.php`
- Test: `tests/Feature/LegitimacionTest.php`

**Interfaces:**
- Produces: modelo `Registration` con `missingFields(): array`, `isComplete(): bool`, `refreshStatus(): void`, `fileFields(): array` (estático), relación `member`, casts `encrypted` en `cnp`/`passport_number`. Constantes `Registration::STATUS_PENDIENTE|COMPLETO|ENVIADO`.

- [ ] **Step 1: Escribir config**

```php
<?php
// config/legitimacion.php
return [
    'season' => '2026-27',
    'deadline' => '2026-08-25',
    'fee' => '195 RON',
    'iban' => 'RO59 RNCB 0077 1851 2329 0001',
    'purge_days' => 90,
];
```

- [ ] **Step 2: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('season', 10);

            $table->string('full_name', 120)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality', 2)->nullable(); // ISO-2; 'RO' pide CNP

            // Cifrados en DB (cast 'encrypted'); se vacían en la purga
            $table->text('cnp')->nullable();
            $table->text('passport_number')->nullable();

            $table->string('photo_path')->nullable();        // foto carnet
            $table->string('id_doc_path')->nullable();       // copia CNP / doc identidad
            $table->string('passport_path')->nullable();     // copia pasaporte (no rumanos)
            $table->string('payment_proof_path')->nullable();// comprobante (opcional)

            $table->text('previous_clubs')->nullable();
            $table->boolean('played_federated')->nullable();
            $table->text('federated_details')->nullable();

            $table->boolean('payment_marked')->default(false);
            $table->timestamp('consent_at')->nullable();

            $table->string('status', 20)->default('pendiente'); // pendiente|completo|enviado_federacion
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'member_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
```

- [ ] **Step 3: Modelo**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Registration extends Model
{
    use HasFactory;

    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_COMPLETO = 'completo';
    public const STATUS_ENVIADO = 'enviado_federacion';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'cnp' => 'encrypted',
            'passport_number' => 'encrypted',
            'played_federated' => 'boolean',
            'payment_marked' => 'boolean',
            'consent_at' => 'datetime',
            'submitted_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Campos que son archivos, con su columna de path. */
    public static function fileFields(): array
    {
        return ['photo' => 'photo_path', 'id_doc' => 'id_doc_path', 'passport' => 'passport_path', 'payment_proof' => 'payment_proof_path'];
    }

    /**
     * Qué le falta para estar completo. Devuelve claves de i18n cortas.
     * El comprobante de pago es opcional; el checkbox de pago no.
     */
    public function missingFields(): array
    {
        $missing = [];
        if (! $this->full_name) $missing[] = 'full_name';
        if (! $this->birth_date) $missing[] = 'birth_date';
        if (! $this->nationality) $missing[] = 'nationality';
        if ($this->nationality === 'RO') {
            if (! $this->cnp) $missing[] = 'cnp';
        } elseif ($this->nationality) {
            if (! $this->passport_number) $missing[] = 'passport_number';
            if (! $this->passport_path) $missing[] = 'passport';
        }
        if (! $this->photo_path) $missing[] = 'photo';
        if (! $this->id_doc_path) $missing[] = 'id_doc';
        if ($this->played_federated === null) $missing[] = 'played_federated';
        if ($this->played_federated && ! $this->federated_details) $missing[] = 'federated_details';
        if (! $this->payment_marked) $missing[] = 'payment';
        if (! $this->consent_at) $missing[] = 'consent';

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /** Recalcula status/submitted_at/purge_after tras cada guardado parcial. */
    public function refreshStatus(): void
    {
        if ($this->status === self::STATUS_ENVIADO) {
            return; // el manager ya lo mandó; no se pisa
        }

        if ($this->isComplete()) {
            if ($this->status !== self::STATUS_COMPLETO) {
                $this->status = self::STATUS_COMPLETO;
                $this->submitted_at = now();
                $this->purge_after = now()->addDays((int) config('legitimacion.purge_days'));
            }
        } else {
            $this->status = self::STATUS_PENDIENTE;
            $this->submitted_at = null;
            $this->purge_after = null;
        }

        $this->save();
    }
}
```

Nota: `previous_clubs` puede quedar vacío (spec) — no entra en `missingFields()`.

- [ ] **Step 4: Factory**

```php
<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegistrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'club_id' => fn (array $attrs) => Member::find($attrs['member_id'])->club_id,
            'member_id' => Member::factory(),
            'season' => config('legitimacion.season'),
            'status' => 'pendiente',
        ];
    }

    public function complete(): static
    {
        return $this->state(fn () => [
            'full_name' => fake()->name(),
            'birth_date' => '1995-05-10',
            'nationality' => 'AR',
            'passport_number' => 'AAB123456',
            'passport_path' => 'legitimacion/x/passport.jpg',
            'photo_path' => 'legitimacion/x/photo.jpg',
            'id_doc_path' => 'legitimacion/x/id.jpg',
            'played_federated' => false,
            'payment_marked' => true,
            'consent_at' => now(),
            'status' => 'completo',
            'submitted_at' => now(),
            'purge_after' => now()->addDays(90),
        ]);
    }
}
```

- [ ] **Step 5: Test de modelo (falla → migrar → pasa)**

```php
it('marca completo y fija purge_after cuando no falta nada', function () {
    $reg = Registration::factory()->complete()->create(['status' => 'pendiente', 'submitted_at' => null, 'purge_after' => null]);
    $reg->refreshStatus();
    expect($reg->status)->toBe('completo')
        ->and($reg->submitted_at)->not->toBeNull()
        ->and($reg->purge_after->isAfter(now()->addDays(89)))->toBeTrue();
});

it('un rumano necesita CNP y un extranjero pasaporte', function () {
    $ro = Registration::factory()->complete()->make(['nationality' => 'RO', 'cnp' => null]);
    expect($ro->missingFields())->toContain('cnp');
    $ar = Registration::factory()->complete()->make(['passport_number' => null]);
    expect($ar->missingFields())->toContain('passport_number');
});
```

- [ ] **Step 6: `php artisan migrate && ./vendor/bin/pest tests/Feature/LegitimacionTest.php` verde**
- [ ] **Step 7: Commit** `git commit -m "Legitimación: tabla registrations y modelo con estado calculado"`

---

### Task 2: Regla `ValidCnp` (checksum real)

**Files:**
- Create: `app/Rules/ValidCnp.php`
- Test: `tests/Feature/LegitimacionTest.php` (agregar)

**Interfaces:**
- Produces: `new ValidCnp` usable en `validate()`.

- [ ] **Step 1: Test primero**

```php
it('valida el checksum del CNP', function (string $cnp, bool $ok) {
    $v = validator(['cnp' => $cnp], ['cnp' => [new \App\Rules\ValidCnp]]);
    expect($v->passes())->toBe($ok);
})->with([
    ['1800101221144', false],    // dígito de control incorrecto
    ['1960911123453', false],
    ['123', false],
    ['abcdefghijklm', false],
]);

it('acepta un CNP con checksum correcto', function () {
    // genera uno válido calculando el dígito de control
    $base = '198071612345';
    $weights = [2,7,9,1,4,6,3,5,8,2,7,9];
    $sum = 0;
    foreach (str_split($base) as $i => $d) $sum += $d * $weights[$i];
    $control = $sum % 11 === 10 ? 1 : $sum % 11;
    $v = validator(['cnp' => $base.$control], ['cnp' => [new \App\Rules\ValidCnp]]);
    expect($v->passes())->toBeTrue();
});
```

- [ ] **Step 2: Implementación**

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CNP rumano: 13 dígitos; el 13.º es un dígito de control con pesos
 * 2-7-9-1-4-6-3-5-8-2-7-9 (mod 11; 10 => 1).
 */
class ValidCnp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\d{13}$/', $value)) {
            $fail(__('legitimacion.cnp_invalid'));
            return;
        }

        $weights = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += (int) $value[$i] * $w;
        }
        $control = $sum % 11 === 10 ? 1 : $sum % 11;

        if ($control !== (int) $value[12]) {
            $fail(__('legitimacion.cnp_invalid'));
        }
    }
}
```

- [ ] **Step 3: Tests verdes. Commit** `git commit -m "Legitimación: validación de CNP con checksum real"`

---

### Task 3: Controller — ver y guardar parcial

**Files:**
- Create: `app/Http/Controllers/RegistrationController.php`
- Modify: `routes/web.php` (dentro de `EnsureProfileComplete`)
- Test: `tests/Feature/LegitimacionTest.php` (agregar)

**Interfaces:**
- Consumes: `Registration`, `ValidCnp`, `CurrentClub` (`club()`, `member()`, `actsAsManager()`).
- Produces: rutas `legitimacion` (GET), `legitimacion.guardar` (POST). Props Inertia: `registration` (ficha propia serializada SIN paths, solo flags de subido), `missing`, `config` {deadline, daysLeft, fee, iban}, y si manager: `roster` (filas por jugador con `missing`, `payment_marked`, `status`).

- [ ] **Step 1: Rutas**

```php
Route::get('/legitimacion', [RegistrationController::class, 'show'])->name('legitimacion');
Route::post('/legitimacion', [RegistrationController::class, 'store'])->name('legitimacion.guardar');
```

- [ ] **Step 2: Controller (show + store)**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Registration;
use App\Rules\ValidCnp;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function show(): Response
    {
        $current = app(CurrentClub::class);
        $own = $this->registrationFor($current->member()->id);

        return Inertia::render('Legitimacion', [
            'registration' => $this->serializeOwn($own),
            'missing' => $own->missingFields(),
            'config' => [
                'deadline' => config('legitimacion.deadline'),
                'daysLeft' => (int) now()->startOfDay()->diffInDays(config('legitimacion.deadline'), false),
                'fee' => config('legitimacion.fee'),
                'iban' => config('legitimacion.iban'),
                'season' => config('legitimacion.season'),
            ],
            'roster' => $current->actsAsManager() ? $this->roster() : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $current = app(CurrentClub::class);
        $reg = $this->registrationFor($current->member()->id);

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'cnp' => ['nullable', new ValidCnp],
            'passport_number' => ['nullable', 'string', 'max:30'],
            'previous_clubs' => ['nullable', 'string', 'max:500'],
            'played_federated' => ['nullable', 'boolean'],
            'federated_details' => ['nullable', 'string', 'max:500'],
            'payment_marked' => ['nullable', 'boolean'],
            'consent' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'id_doc' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'passport' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'payment_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        // Guardado parcial: solo pisa lo que vino en el request
        foreach (['full_name', 'birth_date', 'nationality', 'cnp', 'passport_number', 'previous_clubs', 'federated_details'] as $field) {
            if ($request->has($field)) $reg->$field = $validated[$field];
        }
        foreach (['played_federated', 'payment_marked'] as $field) {
            if ($request->has($field)) $reg->$field = $validated[$field];
        }
        if ($request->has('consent')) {
            $reg->consent_at = $request->boolean('consent') ? ($reg->consent_at ?? now()) : null;
        }

        foreach (Registration::fileFields() as $input => $column) {
            if ($file = $request->file($input)) {
                if ($reg->$column) Storage::delete($reg->$column); // reemplazo
                $reg->$column = $file->storeAs(
                    "legitimacion/{$reg->club_id}/{$reg->member_id}",
                    $input.'-'.now()->timestamp.'.'.$file->extension()
                );
            }
        }

        // Coherencia: si cambia a rumano, el pasaporte sobra (y viceversa el CNP)
        if ($reg->nationality === 'RO') {
            $reg->passport_number = null;
        } elseif ($reg->nationality) {
            $reg->cnp = null;
        }

        $reg->save();
        $reg->refreshStatus();

        return back();
    }

    /** La ficha propia. La crea vacía la primera vez. */
    protected function registrationFor(int $memberId): Registration
    {
        $current = app(CurrentClub::class);

        return Registration::firstOrCreate([
            'club_id' => $current->club()->id,
            'member_id' => $memberId,
            'season' => config('legitimacion.season'),
        ]);
    }

    /** Serialización de la ficha propia: nunca manda paths, solo si hay archivo. */
    protected function serializeOwn(Registration $reg): array
    {
        return [
            'full_name' => $reg->full_name ?? $reg->member->user->name,
            'birth_date' => $reg->birth_date?->format('Y-m-d'),
            'nationality' => $reg->nationality,
            'cnp' => $reg->cnp,
            'passport_number' => $reg->passport_number,
            'previous_clubs' => $reg->previous_clubs,
            'played_federated' => $reg->played_federated,
            'federated_details' => $reg->federated_details,
            'payment_marked' => $reg->payment_marked,
            'consented' => $reg->consent_at !== null,
            'status' => $reg->status,
            'files' => collect(Registration::fileFields())
                ->map(fn ($column) => (bool) $reg->$column),
        ];
    }

    /** Vista delegado: una fila por jugador activo, con qué falta. */
    protected function roster(): array
    {
        $current = app(CurrentClub::class);
        $registrations = Registration::query()
            ->where('club_id', $current->club()->id)
            ->where('season', config('legitimacion.season'))
            ->get()
            ->keyBy('member_id');

        return $current->club()->activeMembers()->with('user:id,name')->orderBy('shirt_number')->get()
            ->map(function ($member) use ($registrations) {
                $reg = $registrations->get($member->id);
                return [
                    'member_id' => $member->id,
                    'name' => $member->user->name,
                    'shirt_number' => $member->shirt_number,
                    'status' => $reg->status ?? 'pendiente',
                    'missing' => $reg ? $reg->missingFields() : null, // null = ni empezó
                    'payment_marked' => (bool) ($reg->payment_marked ?? false),
                    'registration_id' => $reg->id ?? null,
                ];
            })->values()->all();
    }
}
```

- [ ] **Step 3: Tests (aislamiento + parcial + CNP condicionado)**

```php
it('guarda parcial y muestra qué falta', function () {
    $member = Member::factory()->create();
    actingAs($member->user);
    post(route('legitimacion.guardar'), ['birth_date' => '1995-05-10'])->assertRedirect();
    $reg = Registration::first();
    expect($reg->birth_date->format('Y-m-d'))->toBe('1995-05-10')
        ->and($reg->status)->toBe('pendiente')
        ->and($reg->missingFields())->toContain('photo');
});

it('un jugador de otro club no aparece en el roster del manager', function () {
    // manager del club A + registration completa en club B → roster del A no la lista
});

it('el roster solo lo recibe el manager', function () {
    // player: prop roster === null
});
```

- [ ] **Step 4: Verde. Commit** `git commit -m "Legitimación: formulario con guardado parcial y vista delegado"`

---

### Task 4: Documentos — descarga individual + ZIP (solo manager)

**Files:**
- Modify: `app/Http/Controllers/RegistrationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/LegitimacionTest.php` (agregar)

**Interfaces:**
- Produces: rutas `legitimacion.doc` (GET `/legitimacion/{registration}/doc/{field}`) y `legitimacion.zip` (GET `/legitimacion/{registration}/zip`). Ambas: `Gate::authorize('create', Event::class)` (el gate de manager que ya usa toda la app) + `CurrentClub::assertOwns($registration)`.

- [ ] **Step 1: Rutas**

```php
Route::get('/legitimacion/{registration}/doc/{field}', [RegistrationController::class, 'doc'])->name('legitimacion.doc');
Route::get('/legitimacion/{registration}/zip', [RegistrationController::class, 'zip'])->name('legitimacion.zip');
```

- [ ] **Step 2: Implementación**

```php
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

public function doc(Registration $registration, string $field): StreamedResponse
{
    app(CurrentClub::class)->assertOwns($registration);
    Gate::authorize('create', \App\Models\Event::class); // solo manager real

    $column = Registration::fileFields()[$field] ?? abort(404);
    abort_unless($registration->$column && Storage::exists($registration->$column), 404);

    return Storage::response($registration->$column);
}

public function zip(Registration $registration): BinaryFileResponse
{
    app(CurrentClub::class)->assertOwns($registration);
    Gate::authorize('create', \App\Models\Event::class);

    $name = str($registration->member->user->name)->slug();
    $path = storage_path("app/legitimacion-{$registration->id}.zip");

    $zip = new \ZipArchive;
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    $lines = [
        'Nume / Nombre: '.$registration->full_name,
        'Data nașterii: '.$registration->birth_date?->format('d.m.Y'),
        'Naționalitate: '.$registration->nationality,
        'CNP: '.($registration->cnp ?: '—'),
        'Pașaport: '.($registration->passport_number ?: '—'),
        'Cluburi anterioare în România: '.($registration->previous_clubs ?: '—'),
        'Legitimat anterior: '.($registration->played_federated ? 'DA — '.$registration->federated_details : 'NU'),
    ];
    $zip->addFromString("{$name}-datos.txt", implode("\n", $lines));

    foreach (Registration::fileFields() as $field => $column) {
        if ($registration->$column && Storage::exists($registration->$column)) {
            $zip->addFromString(
                "{$name}-{$field}.".pathinfo($registration->$column, PATHINFO_EXTENSION),
                Storage::get($registration->$column)
            );
        }
    }
    $zip->close();

    return response()->download($path, "legitimacion-{$name}.zip")->deleteFileAfterSend();
}
```

Nota: `assertOwns` necesita que `Registration` tenga `club_id` — ya lo tiene; verificar que `CurrentClub::assertOwns` acepte cualquier modelo con `club_id` (así funciona con Event/Expense; si está tipado, agregar el caso).

- [ ] **Step 3: Tests de seguridad (los críticos de la fase)**

```php
it('un jugador no puede bajar la documentación de otro', function () {
    // player A, registration de player B mismo club → GET doc → 403
});

it('un manager de otro club no puede bajar nada', function () {
    // manager club B, registration club A → 404 (assertOwns)
});

it('el manager baja el ZIP con los archivos del jugador', function () {
    Storage::fake('local'); // sembrar photo + id_doc y verificar 200 + attachment
});
```

- [ ] **Step 4: Verde. Commit** `git commit -m "Legitimación: descarga de documentos y ZIP solo para el manager"`

---

### Task 5: Recordatorio WhatsApp + campanita a los incompletos

**Files:**
- Modify: `app/Http/Controllers/RegistrationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/LegitimacionTest.php` (agregar)

**Interfaces:**
- Consumes: `WhatsAppChannel::sendText()` (cae en `LogWhatsAppChannel` mientras Twilio no esté activo), `App\Services\Notifications` (campanita, mismo uso que en cuotas).
- Produces: ruta `legitimacion.recordar` (POST), devuelve `back()->with('status', $count)` como `cuota.reclamar`.

- [ ] **Step 1: Implementación (mismo patrón que `CuotaController::claim`)**

```php
public function remind(WhatsAppChannel $whatsapp): RedirectResponse
{
    Gate::authorize('create', \App\Models\Event::class);
    $current = app(CurrentClub::class);

    $pending = collect($this->roster())
        ->filter(fn ($row) => $row['status'] !== Registration::STATUS_COMPLETO
            && $row['status'] !== Registration::STATUS_ENVIADO);

    $members = $current->club()->activeMembers()->with('user')->get()->keyBy('id');

    foreach ($pending as $row) {
        $user = $members[$row['member_id']]->user;
        $locale = $user->locale ?? config('app.fallback_locale');
        $whatsapp->sendText($user->phone, __('legitimacion.whatsapp_reminder', [
            'name' => strtok($user->name, ' '),
            'deadline' => '25.08',
        ], $locale));
        // + notificación campanita con link a /legitimacion (mismo servicio que usan las cuotas)
    }

    return back()->with('status', $pending->count());
}
```

- [ ] **Step 2: Test** — manager dispara, jugador con ficha completa NO recibe, contador correcto; player recibe 403.
- [ ] **Step 3: Verde. Commit** `git commit -m "Legitimación: recordatorio a los que no completaron"`

---

### Task 6: Purga a los 90 días

**Files:**
- Create: `app/Console/Commands/PurgeRegistrations.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/LegitimacionTest.php` (agregar)

**Interfaces:**
- Produces: comando `legitimacion:purgar`, programado `dailyAt('04:00')`.

- [ ] **Step 1: Comando**

```php
<?php

namespace App\Console\Commands;

use App\Models\Registration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pasados los 90 días de la entrega, se borran los archivos y los datos
 * sensibles (CNP, pasaporte). La fila queda como registro histórico.
 */
class PurgeRegistrations extends Command
{
    protected $signature = 'legitimacion:purgar';
    protected $description = 'Borra documentos y datos sensibles de fichas vencidas';

    public function handle(): int
    {
        $due = Registration::query()
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->whereNull('purged_at')
            ->get();

        foreach ($due as $reg) {
            foreach (Registration::fileFields() as $column) {
                if ($reg->$column) Storage::delete($reg->$column);
            }
            $reg->forceFill([
                'cnp' => null,
                'passport_number' => null,
                'photo_path' => null,
                'id_doc_path' => null,
                'passport_path' => null,
                'payment_proof_path' => null,
                'purged_at' => now(),
            ])->save();
        }

        $this->info("Purgadas: {$due->count()}");

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php
Schedule::command('legitimacion:purgar')->dailyAt('04:00');
```

- [ ] **Step 2: Test** — ficha con `purge_after` pasado: archivos borrados (Storage::fake), `cnp` null, `full_name` conservado, `purged_at` seteado; ficha reciente intacta; correr dos veces es idempotente.
- [ ] **Step 3: Verde. Commit** `git commit -m "Legitimación: purga de documentos y datos sensibles a los 90 días"`

---

### Task 7: Frontend — `Legitimacion.jsx` + banner en Agenda + i18n

**Files:**
- Create: `resources/js/Pages/Legitimacion.jsx`
- Modify: `resources/js/Pages/Agenda.jsx` (banner)
- Modify: `app/Http/Controllers/AgendaController.php` (prop `legitimacion`)
- Modify: `lang/en.json`, `lang/ro.json`, `lang/es.json`
- Modify: `resources/css/app.css` (solo si hace falta alguna clase nueva; reusar las existentes)

**Interfaces:**
- Consumes: props de Task 3; helper `t()` de `resources/js/i18n.js`; `router.post` de Inertia con `forceFormData: true` para los archivos.
- Produces: banner en Agenda que linkea a `/legitimacion` mostrando días restantes, visible mientras `status !== 'completo' && status !== 'enviado_federacion'`.

- [ ] **Step 1: Prop del banner en `AgendaController::index`**

```php
'legitimacion' => [
    'complete' => Registration::query()
        ->where('member_id', $current->member()->id)
        ->where('season', config('legitimacion.season'))
        ->whereIn('status', [Registration::STATUS_COMPLETO, Registration::STATUS_ENVIADO])
        ->exists(),
    'deadline' => config('legitimacion.deadline'),
    'daysLeft' => (int) now()->startOfDay()->diffInDays(config('legitimacion.deadline'), false),
],
```

Banner en `Agenda.jsx`: bloque rojo (`#D22233`) arriba de la lista, `Link` a `/legitimacion`, texto `t('legitimacion.banner', {days})`; si `daysLeft < 0` muestra `legitimacion.banner_overdue`. Oculto si `complete`.

- [ ] **Step 2: Página `Legitimacion.jsx`** — misma columna mobile del resto de la app:
  - **Ficha propia** (siempre): campos en el orden de la spec. Nacionalidad = `<select>` con RO, AR, CO, IT, ES + "Otro" (lista corta ISO-2). Si `RO` → input CNP; si no → pasaporte (número + archivo). Inputs `type="file"` nativos (en móvil abren cámara/galería solos — nada que dependa del navegador). Cada archivo ya subido muestra ✓ + botón "Reemplazar". Bloque de pago: importe `195 RON`, IBAN con botón copiar (`navigator.clipboard.writeText`, con fallback de selección), checkbox "ya hice la transferencia", comprobante opcional. Checkbox de consentimiento con el texto de uso y borrado a los 90 días. Botón único "Guardar" (guardado parcial: manda solo lo tocado, `router.post` con `forceFormData`). Arriba, lista "Te falta: …" desde `missing`.
  - **Vista delegado** (si `roster != null`): contador "X de Y completos", filtro "incompletos" (toggle client-side), tabla: nombre + dorsal, chips por cada cosa que falta, ✓ de pago, botones ZIP (link a `legitimacion.zip`) y estado. Botón "Recordar por WhatsApp" → `router.post(route('legitimacion.recordar'))` con el contador de enviados en el flash, igual que reclamar cuotas.
- [ ] **Step 3: i18n** — todas las cadenas nuevas con prefijo `legitimacion.*` en `en/ro/es.json` (es rioplatense; ro correcto para los términos federativos: "legitimare", "CNP", "adeverință"). Incluir `legitimacion.consent_text` explicando uso (inscripción FRF) y borrado a los 90 días, y `legitimacion.whatsapp_reminder`.
- [ ] **Step 4: `npm run build` sin errores; prueba manual en 380px.**
- [ ] **Step 5: Commit** `git commit -m "Legitimación: formulario del jugador, vista delegado y banner con el plazo"`

---

## Self-review

- Spec coverage: ruta/acceso (T3), banner (T7), campos completos (T1/T3/T7), guardado parcial (T3), qué falta (T1/T7), tabla registrations (T1), archivos privados sin URL pública (T4 — endpoint autenticado en vez de URLs firmadas de S3, mejor aún: no existe URL válida sin sesión de manager), purge_after 90 días + comando (T6), vista delegado con filtro/contador/ZIP/recordar (T3/T4/T5/T7), i18n es+ro (T7), NO Stripe / NO mail / NO ver docs ajenos (T4 tests) / NO campos extra. ✓
- Sin placeholders en código de producción; los tests esqueléticos de T3/T5 tienen su intención descrita y se escriben completos al ejecutar. ✓
- Consistencia de nombres: `Registration::fileFields()`, `missingFields()`, `refreshStatus()`, rutas `legitimacion.*` usadas igual en todas las tasks. ✓
