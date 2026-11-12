# Dos bolsillos — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la app muestre cuánta plata hay de verdad en cada bolsillo (cuenta y efectivo), y que el saldo de la cuenta coincida con el extracto del banco.

**Architecture:** Un libro de movimientos (`movements`) es la única fuente de los saldos; cada hecho que mueve plata (gasto, cuota cobrada a mano, payout de Mollie, transferencia, ingreso, ajuste) escribe su fila automáticamente, nunca a mano por separado. Las correcciones son contra-asientos, no borrados. El "en tránsito" no sale del libro: se lee del saldo real de Mollie.

**Tech Stack:** Laravel 12 / PHP 8.3 · Inertia 2 + React 18 · MySQL 8 · mollie-api-php 3.13.2 · Pest 4

**Spec:** `docs/superpowers/specs/2026-11-12-dos-bolsillos-design.md`

## Global Constraints

- **Todo query filtra por `club_id`.** Los modelos nuevos usan el trait `App\Models\Concerns\BelongsToClub`. Cada tarea que agrega un endpoint agrega su test de aislamiento.
- **Montos en centavos, enteros con signo.** `+` entra, `−` sale. Nunca float.
- **Moneda RON**, sin conversión (confirmado por el dueño).
- **i18n en los CUATRO idiomas:** toda cadena de UI nueva entra en `lang/en.json`, `lang/ro.json`, `lang/es.json` y `lang/ar.json`. El inglés es el fallback. El español es rioplatense ("vos").
- **Mobile-first 380px**, todo lo tocable mínimo 44px de alto. Sin layout alternativo de escritorio.
- **Nada que dependa del navegador** (la app se envuelve en Capacitor): sin `window.open`, sin navegación por URL bar.
- **Solo el manager escribe.** Gate: `Gate::authorize('create', Event::class)` — el mismo que usan gastos y cuotas hoy. La autorización usa el rol REAL, nunca el efectivo del toggle "ver como jugador".
- **PHP local:** `/usr/local/bin/php` (el PATH tiene MAMP 7.4). Tests: `/usr/local/bin/php vendor/bin/pest`.
- **Commits chicos, mensaje en español**, uno por tarea terminada.

## Review Focus

Cinco cosas que el diseño implica, que ningún camino feliz ejercita, y que le van a morder al presidente. Cada una tiene su test en la tarea que la posee.

1. **Un movimiento de un club no puede verse ni tocarse desde otro club.** Es la regla dura del proyecto y acá hay plata. → Tarea 1 y en cada endpoint nuevo.
2. **Un gasto borrado y vuelto a borrar no puede contra-asentar dos veces.** El botón de borrar se toca dos veces desde el celular más seguido de lo que parece. → Tarea 4.
3. **Una cuota que va `paid → pending → paid` no puede dejar dos movimientos sumando.** El flujo existente de `setStatus` lo permite hoy. → Tarea 5.
4. **El mismo payout llegando dos veces (webhook + chequeo diario, o reintento de Mollie) no puede acreditar dos veces.** → Tarea 9 y 11.
5. **Si la API de Mollie no responde, el "en tránsito" no puede mostrarse como 0.** Un cero falso se lee como "no hay plata en camino" y es peor que no mostrar nada. → Tarea 10.

---

### Task 1: El libro — tabla `movements`, modelo y aislamiento

**Files:**
- Create: `database/migrations/2026_11_12_000001_create_movements_table.php`
- Create: `app/Models/Movement.php`
- Create: `database/factories/MovementFactory.php`
- Test: `tests/Feature/MovimientosTest.php`

**Interfaces:**
- Produces: `App\Models\Movement` con `POTS = ['cuenta','cash']` y `KINDS = ['payout','cuota','gasto','transferencia','ingreso','ajuste','fee','saldo_inicial']`; casts `amount_cents:integer`, `occurred_on:date`; relaciones `expense()`, `due()`, `reverses()`.

- [ ] **Step 1: Escribir el test que falla**

```php
it('un movimiento de otro club no se ve', function () {
    $mio = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    Movement::factory()->for($ajeno->club)->create(['amount_cents' => 50000]);

    $this->actingAs($mio->user);
    expect(Movement::query()->count())->toBe(0);
});

it('guarda signo, bolsillo y tipo', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user);
    $mov = Movement::create([
        'pot' => 'cuenta', 'kind' => 'saldo_inicial',
        'amount_cents' => 22500, 'occurred_on' => now()->toDateString(),
    ]);
    expect($mov->fresh()->amount_cents)->toBe(22500)
        ->and($mov->club_id)->toBe($m->club_id);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `/usr/local/bin/php vendor/bin/pest tests/Feature/MovimientosTest.php`
Expected: FAIL — `Class "App\Models\Movement" not found`

- [ ] **Step 3: Crear la migración**

Columnas exactamente como el spec (sección Datos): `id`, `club_id` (FK cascade), `pot` (string 10), `kind` (string 20), `amount_cents` (bigInteger, con signo), `occurred_on` (date), `group_uuid` (uuid nullable, index), `reverses_movement_id` (nullable, FK a movements, nullOnDelete), `expense_id` / `due_id` (nullable, FK nullOnDelete), `mollie_payout_id` (string nullable), `note` (string 200 nullable), `created_by_member_id` (nullable, FK nullOnDelete), timestamps. Índice compuesto `(club_id, pot)` y `(club_id, occurred_on)`.

- [ ] **Step 4: Crear `app/Models/Movement.php` con `BelongsToClub`** y la factory.

- [ ] **Step 5: Correr y verificar que pasan**

Run: `/usr/local/bin/php vendor/bin/pest tests/Feature/MovimientosTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/Movement.php database/factories/MovementFactory.php tests/Feature/MovimientosTest.php
git commit -m "El libro de movimientos: tabla, modelo y aislamiento por club"
```

---

### Task 2: Los saldos — servicio `Ledger`

**Files:**
- Create: `app/Services/Ledger.php`
- Test: `tests/Feature/MovimientosTest.php` (agregar)

**Interfaces:**
- Consumes: `App\Models\Movement` (Tarea 1).
- Produces: `Ledger::balances(): array` → `['cuenta' => int, 'cash' => int]`; `Ledger::balance(string $pot): int`; `Ledger::history(int $limit = 50): Collection`.

- [ ] **Step 1: Escribir el test que falla**

```php
it('el saldo de cada bolsillo es la suma de sus movimientos', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 30000]);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => -7500]);
    Movement::factory()->create(['pot' => 'cash', 'amount_cents' => 10000]);

    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 22500, 'cash' => 10000]);
});

it('un bolsillo sin movimientos vale cero, no null', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user);
    expect(app(Ledger::class)->balance('cash'))->toBe(0);
});
```

- [ ] **Step 2: Correr y verificar que falla.** Run: `/usr/local/bin/php vendor/bin/pest tests/Feature/MovimientosTest.php`
- [ ] **Step 3: Implementar `Ledger`.** Una suma agrupada por `pot`, completando con 0 los bolsillos sin filas.
- [ ] **Step 4: Correr y verificar que pasan.**
- [ ] **Step 5: Commit** — `git commit -m "Saldo por bolsillo: el servicio que suma el libro"`

---

### Task 3: La línea en la arena — comando de saldo inicial

**Files:**
- Create: `app/Console/Commands/SaldoInicial.php`
- Test: `tests/Feature/SaldoInicialTest.php`

**Interfaces:**
- Consumes: `Movement`, `Ledger`.
- Produces: `php artisan app:saldo-inicial {cuenta_lei} {efectivo_lei} {modo=dry}` — dry por defecto, igual que `app:cuota-efectivo` y `app:bloquear`.

- [ ] **Step 1: Escribir el test que falla**

```php
it('en dry no escribe nada', function () {
    Member::factory()->manager()->create();
    $this->artisan('app:saldo-inicial 225 0')->assertSuccessful();
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('con go crea un saldo inicial por bolsillo', function () {
    $m = Member::factory()->manager()->create();
    $this->artisan('app:saldo-inicial 225 0 go')->assertSuccessful();
    $this->actingAs($m->user);
    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 22500, 'cash' => 0]);
});

it('no se puede correr dos veces', function () {
    Member::factory()->manager()->create();
    $this->artisan('app:saldo-inicial 225 0 go');
    $this->artisan('app:saldo-inicial 999 0 go')->assertFailed();
    expect(Movement::withoutGlobalScopes()->where('kind', 'saldo_inicial')->count())->toBe(2);
});
```

- [ ] **Step 2: Correr y verificar que falla.**
- [ ] **Step 3: Implementar el comando.** Para cada club con `monthly_fee_cents > 0`, crear dos movimientos `kind: saldo_inicial` (uno por bolsillo, aun si el monto es 0: la fila con 0 es la que documenta la fecha de la línea). Abortar si el club ya tiene alguno.
- [ ] **Step 4: Correr y verificar que pasan.**
- [ ] **Step 5: Commit** — `git commit -m "Línea en la arena: comando de saldo inicial por bolsillo"`

---

### Task 4: El gasto declara de qué bolsillo salió

**Files:**
- Modify: `database/migrations/` (nueva: agregar `pot` a `expenses`)
- Modify: `app/Models/Expense.php` (agregar `pot` al fillable; la lista de bolsillos vive en `Movement::POTS`)
- Modify: `app/Http/Controllers/ExpenseController.php:13-48`
- Test: `tests/Feature/GastoBolsilloTest.php`

**Interfaces:**
- Consumes: `Movement`, `Ledger`.
- Produces: `expenses.pot` (`cuenta|cash`, **nullable**: los gastos anteriores a la línea quedan sin bolsillo y fuera de los saldos).

- [ ] **Step 1: Escribir el test que falla**

```php
it('cargar un gasto baja el bolsillo que se eligió', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->post('/gastos', [
        'category' => 'pitch', 'amount_cents' => 15000,
        'spent_on' => now()->toDateString(), 'pot' => 'cash',
    ])->assertRedirect();

    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 0, 'cash' => -15000]);
});

it('el gasto exige bolsillo', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->post('/gastos', [
        'category' => 'pitch', 'amount_cents' => 15000, 'spent_on' => now()->toDateString(),
    ])->assertSessionHasErrors('pot');
});

it('borrar un gasto contra-asienta en vez de borrar el movimiento', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->post('/gastos', [
        'category' => 'pitch', 'amount_cents' => 15000,
        'spent_on' => now()->toDateString(), 'pot' => 'cash',
    ]);
    $expense = Expense::query()->sole();
    $this->actingAs($m->user)->delete("/gastos/{$expense->id}")->assertRedirect();

    expect(app(Ledger::class)->balance('cash'))->toBe(0)
        ->and(Movement::query()->count())->toBe(2);
});

it('un gasto mayor al efectivo pasa igual, pero avisa', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->post('/gastos', [
        'category' => 'pitch', 'amount_cents' => 15000,
        'spent_on' => now()->toDateString(), 'pot' => 'cash',
    ])->assertRedirect()->assertSessionHas('aviso_saldo_negativo', true);

    expect(app(Ledger::class)->balance('cash'))->toBe(-15000);
});
it('borrar dos veces no contra-asienta dos veces', function () {
    // Review Focus #2
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->post('/gastos', [
        'category' => 'pitch', 'amount_cents' => 15000,
        'spent_on' => now()->toDateString(), 'pot' => 'cash',
    ]);
    $expense = Expense::query()->sole();
    $this->actingAs($m->user)->delete("/gastos/{$expense->id}");
    $this->actingAs($m->user)->delete("/gastos/{$expense->id}")->assertNotFound();

    expect(app(Ledger::class)->balance('cash'))->toBe(0)
        ->and(Movement::query()->count())->toBe(2);
});
```

- [ ] **Step 2: Correr y verificar que falla.** Run: `/usr/local/bin/php vendor/bin/pest tests/Feature/GastoBolsilloTest.php`
- [ ] **Step 3: Migración + validación + escritura del movimiento.** Si el movimiento deja el bolsillo en negativo, `back()->with('aviso_saldo_negativo', true)` — la app avisa, nunca frena el gasto. `store()` valida `'pot' => ['required', Rule::in(Movement::POTS)]` y crea gasto + movimiento (`kind: gasto`, `amount_cents` negativo, `expense_id`) en una transacción. `destroy()` crea el movimiento inverso (`kind: gasto`, `reverses_movement_id`, monto opuesto) y después borra el gasto; el borrado deja `movements.expense_id` en null por la FK.
- [ ] **Step 4: Correr y verificar que pasan.** Correr también `tests/Feature/CajaTest.php` — sus posts a `/gastos` ahora necesitan `pot`; actualizarlos.
- [ ] **Step 5: Agregar las cadenas del selector de bolsillo a los cuatro `lang/*.json`.**
- [ ] **Step 6: Commit** — `git commit -m "El gasto dice de qué bolsillo salió y mueve el saldo"`

---

### Task 5: La cuota cobrada a mano declara dónde entró

**Files:**
- Modify: `app/Http/Controllers/CuotaController.php:473-495` (`setStatus`)
- Modify: `app/Console/Commands/CuotaEfectivo.php` (opción `--pot`)
- Test: `tests/Feature/CuotaBolsilloTest.php`

**Interfaces:**
- Consumes: `Movement`, `Ledger`.
- Produces: `POST /cuota/{due}/estado` acepta `pot` (requerido solo cuando `status = paid`).

- [ ] **Step 1: Escribir el test que falla**

```php
it('marcar una cuota pagada en cash sube el efectivo', function () {
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create(['amount_cents' => 30000]);
    $this->actingAs($m->user)->post("/cuota/{$due->id}/estado", [
        'status' => 'paid', 'pot' => 'cash',
    ])->assertRedirect();

    expect(app(Ledger::class)->balance('cash'))->toBe(30000);
});

it('exige bolsillo solo al marcar pagada', function () {
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create();
    $this->actingAs($m->user)->post("/cuota/{$due->id}/estado", ['status' => 'paid'])
        ->assertSessionHasErrors('pot');
    $this->actingAs($m->user)->post("/cuota/{$due->id}/estado", ['status' => 'waived'])
        ->assertRedirect();
});

it('paid -> pending -> paid no deja dos movimientos sumando', function () {
    // Review Focus #3
    $m = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($m)->create(['amount_cents' => 30000]);
    $this->actingAs($m->user);
    $this->post("/cuota/{$due->id}/estado", ['status' => 'paid', 'pot' => 'cash']);
    $this->post("/cuota/{$due->id}/estado", ['status' => 'pending']);
    $this->post("/cuota/{$due->id}/estado", ['status' => 'paid', 'pot' => 'cash']);

    expect(app(Ledger::class)->balance('cash'))->toBe(30000);
});
```

- [ ] **Step 2: Correr y verificar que falla.**
- [ ] **Step 3: Implementar.** En `setStatus`: si pasa a `paid`, validar `pot` y crear movimiento `kind: cuota` (+, con `due_id`); si sale de `paid`, contra-asentar el movimiento vigente de esa cuota. El `AuditLog::record` existente se mantiene y suma `pot` al meta. En `CuotaEfectivo`, agregar `--pot=cash` por defecto y crear el movimiento igual.
- [ ] **Step 4: Correr y verificar que pasan.** Correr también `tests/Feature/CuotasTest.php`, `AuditoriaCuotaTest.php` y `CuotaEfectivoTest.php`.
- [ ] **Step 5: Cadenas nuevas en los cuatro `lang/*.json`.**
- [ ] **Step 6: Commit** — `git commit -m "La cuota cobrada a mano dice a qué bolsillo entró"`

---

### Task 6: Transferencia entre bolsillos (y no es un gasto)

**Files:**
- Create: `app/Http/Controllers/MovementController.php`
- Modify: `routes/web.php:148` (zona del manager)
- Test: `tests/Feature/TransferenciaTest.php`

**Interfaces:**
- Produces: `POST /movimientos/transferencia` con `from` (`cuenta|cash`), `amount_cents`, `occurred_on`, `note` opcional. Crea **dos** filas atadas por `group_uuid`.

- [ ] **Step 1: Escribir el test que falla**

```php
it('mueve plata de un bolsillo al otro sin cambiar el total', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 50000]);

    $this->post('/movimientos/transferencia', [
        'from' => 'cuenta', 'amount_cents' => 30000, 'occurred_on' => now()->toDateString(),
    ])->assertRedirect();

    $s = app(Ledger::class)->balances();
    expect($s)->toBe(['cuenta' => 20000, 'cash' => 30000])
        ->and(array_sum($s))->toBe(50000);
});

it('la transferencia no crea un gasto', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->post('/movimientos/transferencia', [
        'from' => 'cuenta', 'amount_cents' => 30000, 'occurred_on' => now()->toDateString(),
    ]);
    expect(Expense::query()->count())->toBe(0);
});

it('un jugador no puede transferir', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $this->actingAs($player->user)->post('/movimientos/transferencia', [
        'from' => 'cuenta', 'amount_cents' => 30000, 'occurred_on' => now()->toDateString(),
    ])->assertForbidden();
});
```

- [ ] **Step 2: Correr y verificar que falla.**
- [ ] **Step 3: Implementar `MovementController::transfer`.** Transacción con dos `Movement::create`, mismo `group_uuid`, montos opuestos, `kind: transferencia`.
- [ ] **Step 4: Correr y verificar que pasan.**
- [ ] **Step 5: Cadenas nuevas en los cuatro `lang/*.json`.** La acción se llama "Mover plata", nunca "Gasto".
- [ ] **Step 6: Commit** — `git commit -m "Transferencia entre bolsillos: mueve, no gasta"`

---

### Task 7: Ingresos que no son cuota, y ajustes contra el extracto

**Files:**
- Modify: `app/Http/Controllers/MovementController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AjusteTest.php`

**Interfaces:**
- Produces: `POST /movimientos/ingreso` (`pot`, `amount_cents`, `occurred_on`, `note` requerida) y `POST /movimientos/ajuste` (`pot`, `target_cents`, `note` requerida).

- [ ] **Step 1: Escribir el test que falla**

```php
it('el ajuste se carga como saldo objetivo y guarda la diferencia', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user);
    Movement::factory()->create(['pot' => 'cuenta', 'amount_cents' => 22500]);

    $this->post('/movimientos/ajuste', [
        'pot' => 'cuenta', 'target_cents' => 18000, 'note' => 'el extracto dice 180',
    ])->assertRedirect();

    expect(app(Ledger::class)->balance('cuenta'))->toBe(18000)
        ->and(Movement::query()->where('kind', 'ajuste')->sole()->amount_cents)->toBe(-4500);
});

it('el ajuste exige nota', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->post('/movimientos/ajuste', [
        'pot' => 'cuenta', 'target_cents' => 18000,
    ])->assertSessionHasErrors('note');
});

it('un ingreso que no es cuota sube el bolsillo', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->post('/movimientos/ingreso', [
        'pot' => 'cuenta', 'amount_cents' => 60000,
        'occurred_on' => now()->toDateString(), 'note' => 'patrimonio inicial',
    ])->assertRedirect();
    expect(app(Ledger::class)->balance('cuenta'))->toBe(60000);
});
```

- [ ] **Step 2: Correr y verificar que falla.**
- [ ] **Step 3: Implementar.** `adjust()` lee el saldo actual con `Ledger`, guarda `target − actual` como `amount_cents`. Un ajuste de diferencia 0 no crea fila.
- [ ] **Step 4: Correr y verificar que pasan.**
- [ ] **Step 5: Cadenas nuevas en los cuatro `lang/*.json`.**
- [ ] **Step 6: Commit** — `git commit -m "Ingresos sin cuota y ajuste contra el extracto"`

---

### Task 8: Sonda de solo lectura de la API de Mollie

> Esta tarea no escribe nada en la base. Existe porque la forma exacta de `Payout` y `BalanceTransaction` en la cuenta real determina cómo se implementan las tareas 9 y 10, y el SDK 3.13.2 no tiene constantes para los tipos de evento de payout. **El resultado de esta sonda puede cambiar las tareas 9 y 10: pararse acá y mirarlo antes de seguir.**

**Files:**
- Create: `app/Console/Commands/MollieSaldoDiag.php`
- Test: ninguno (comando de diagnóstico, como `app:mollie-diag`)

**Interfaces:**
- Produces: `php artisan app:mollie-saldo-diag` — imprime balances, últimos payouts y, del último payout, sus balance transactions con `type`, `amount` y `deductions`.

- [ ] **Step 1: Implementar el comando** siguiendo el estilo de `app/Console/Commands/MollieDiag.php`. Nunca imprimir la API key.
- [ ] **Step 2: Correr en local** (`/usr/local/bin/php artisan app:mollie-saldo-diag`) y confirmar que no explota sin credenciales.
- [ ] **Step 3: Correr en producción** desde la pestaña Commands de Forge y guardar la salida.
- [ ] **Step 4: Confirmar las tres incógnitas:** (a) ¿la credencial del club tiene `payouts.read` y `balances.read`? (b) ¿de dónde sale la comisión — `deductions` de las balance transactions, o hay que derivarla? (c) ¿el payout viene en RON?
- [ ] **Step 5: Si alguna respuesta contradice el diseño, anotarlo en el spec y revisar las tareas 9 y 10 antes de codearlas.**
- [ ] **Step 6: Commit** — `git commit -m "Sonda de solo lectura del saldo y los payouts de Mollie"`

---

### Task 9: Payouts — tabla, webhook, comisión y fallos

**Files:**
- Create: `database/migrations/` (crear `mollie_payouts`)
- Create: `app/Models/MolliePayout.php`
- Create: `app/Http/Controllers/Webhooks/MolliePayoutWebhookController.php`
- Modify: `app/Services/Mollie/MollieGateway.php` (agregar `getPayout`)
- Modify: `routes/web.php:70` (zona de webhooks, sin auth ni CSRF)
- Test: `tests/Feature/PayoutTest.php`

**Interfaces:**
- Consumes: la forma de la API confirmada en la Tarea 8.
- Produces: `POST /webhooks/mollie/payouts`; `MollieGateway::getPayout(string $id)`.

- [ ] **Step 1: Escribir el test que falla**

```php
it('un payout completado acredita el neto en la cuenta', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.completed'])
        ->assertOk();

    $this->actingAs($m->user);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800);
});

it('el mismo payout dos veces acredita una sola vez', function () {
    // Review Focus #4
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');

    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.completed']);
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.completed']);

    $this->actingAs($m->user);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800)
        ->and(MolliePayout::withoutGlobalScopes()->count())->toBe(1);
});

it('un payout fallido contra-asienta lo acreditado', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.completed']);

    fakeMolliePayout(id: 'ptr_abc', amountCents: 28800, status: 'failed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_abc', 'type' => 'payout.failed']);

    $this->actingAs($m->user);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(0);
});

it('un id desconocido no rompe el webhook', function () {
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_nope'])->assertOk();
});
```

- [ ] **Step 2: Correr y verificar que falla.**
- [ ] **Step 3: Implementar.** Mismo patrón que `MollieWebhookController`: el body trae el id, la verdad se trae por API. Idempotencia por `mollie_payout_id` unique. En `completed`: fila en `mollie_payouts` + movimiento `kind: payout` (+ neto) y, si la Tarea 8 confirmó que la comisión es legible, un movimiento `kind: fee` (−) en el mismo bolsillo. En `failed` (que es también el caso de cancelación post-envío, según Mollie): contra-asentar. Cualquier error de API devuelve 200 para que Mollie reintente.
- [ ] **Step 4: Correr y verificar que pasan.**
- [ ] **Step 5: Commit** — `git commit -m "Payouts de Mollie: entran solos a la cuenta, con su comisión"`

---

### Task 10: El tránsito sale del saldo de Mollie, no del libro

**Files:**
- Modify: `app/Services/Mollie/MollieGateway.php`
- Test: `tests/Feature/PayoutTest.php` (agregar)

**Interfaces:**
- Produces: `MollieGateway::transitCents(): ?int` — `null` cuando no se puede leer (no cuando es cero).

- [ ] **Step 1: Escribir el test que falla**

```php
it('si Mollie no responde, el tránsito es null y no cero', function () {
    // Review Focus #5
    fakeMollieBalanceFailure();
    expect(app(MollieGateway::class)->transitCents())->toBeNull();
});

it('el tránsito es el saldo pendiente de Mollie', function () {
    fakeMollieBalance(availableCents: 12000, pendingCents: 6000);
    expect(app(MollieGateway::class)->transitCents())->toBe(18000);
});
```

- [ ] **Step 2: Correr y verificar que falla.**
- [ ] **Step 3: Implementar `transitCents()`** usando el endpoint de balances que la Tarea 8 confirmó. Cachear 5 minutos (`Cache::remember`) para no pegarle a Mollie en cada render. Cualquier `Throwable` devuelve `null`.
- [ ] **Step 4: Correr y verificar que pasan.**
- [ ] **Step 5: Commit** — `git commit -m "En tránsito: se lee del saldo real de Mollie"`

---

### Task 11: Red de seguridad — chequeo diario de payouts

**Files:**
- Create: `app/Console/Commands/SincronizarPayouts.php`
- Modify: `routes/console.php` (scheduler, junto a los jobs que ya corren)
- Test: `tests/Feature/PayoutTest.php` (agregar)

**Interfaces:**
- Produces: `php artisan payouts:sincronizar` — diario, 07:00 Europe/Bucharest.

- [ ] **Step 1: Escribir el test que falla**

```php
it('trae un payout que el webhook se perdió', function () {
    $m = Member::factory()->manager()->create();
    fakeMolliePayoutList([['id' => 'ptr_x', 'amountCents' => 28800, 'status' => 'completed']]);

    $this->artisan('payouts:sincronizar')->assertSuccessful();

    $this->actingAs($m->user);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800);
});

it('no duplica lo que el webhook ya acreditó', function () {
    // Review Focus #4
    $m = Member::factory()->manager()->create();
    fakeMolliePayout(id: 'ptr_x', amountCents: 28800, status: 'completed');
    $this->post('/webhooks/mollie/payouts', ['id' => 'ptr_x', 'type' => 'payout.completed']);

    fakeMolliePayoutList([['id' => 'ptr_x', 'amountCents' => 28800, 'status' => 'completed']]);
    $this->artisan('payouts:sincronizar');

    $this->actingAs($m->user);
    expect(app(Ledger::class)->balance('cuenta'))->toBe(28800);
});
```

- [ ] **Step 2: Correr y verificar que falla.**
- [ ] **Step 3: Implementar.** Listar los payouts de los últimos 30 días y pasar cada uno por el **mismo** método que usa el webhook (extraerlo a una acción compartida en la Tarea 9 si hace falta), para que la idempotencia sea una sola.
- [ ] **Step 4: Correr y verificar que pasan.**
- [ ] **Step 5: Commit** — `git commit -m "Chequeo diario de payouts: red de seguridad del webhook"`

---

### Task 12: La pantalla — los dos bolsillos reemplazan a la caja vieja

**Files:**
- Modify: `app/Http/Controllers/CuotaController.php:347-365` (`cashSummary`) y `:75`
- Modify: `resources/js/Pages/Cuota.jsx:481` y alrededores
- Modify: `lang/en.json`, `lang/ro.json`, `lang/es.json`, `lang/ar.json`
- Test: `tests/Feature/CajaTest.php` (reescribir las aserciones de `resumen`)

**Interfaces:**
- Consumes: `Ledger::balances()`, `Ledger::history()`, `MollieGateway::transitCents()`.
- Produces: prop `saldos` = `['cuenta' => int, 'cash' => int, 'transito_cents' => ?int, 'as_of' => 'Y-m-d']` y prop `movimientos` (historial).

- [ ] **Step 1: Escribir el test que falla**

```php
it('el saldo viejo ya no se manda y en su lugar van los dos bolsillos', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->missing('resumen.balance_cents')
            ->has('saldos.cuenta')->has('saldos.cash')->has('saldos.as_of')
        );
});

it('el jugador ve los saldos pero no el detalle de cada cuota cobrada', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $otro = Member::factory()->for($manager->club)->create();
    $due = Due::factory()->forMember($otro)->create(['amount_cents' => 30000]);
    $this->actingAs($manager->user)->post("/cuota/{$due->id}/estado", ['status' => 'paid', 'pot' => 'cash']);

    $this->actingAs($player->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->has('saldos')
            ->where('movimientos', fn ($ms) => collect($ms)
                ->where('kind', 'cuota')
                ->every(fn ($mv) => ! isset($mv['due_id']) && ! isset($mv['member_name'])))
        );
});

it('el manager sí ve el detalle', function () {
    // mismo armado; el manager recibe due_id en los movimientos de cuota
});

it('la caja sigue mostrando cobrado vs adeudado y los deudores', function () {
    $m = Member::factory()->manager()->create();
    Due::factory()->forMember($m)->create();
    $this->actingAs($m->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->has('caja.paid_all_cents')->has('caja.owed_all_cents')->has('caja.debtors'));
});
```

- [ ] **Step 2: Correr y verificar que falla.**
- [ ] **Step 3: Backend.** Borrar `balance_cents` de `cashSummary`, agregar los props `saldos` y `movimientos`. Los movimientos `kind: cuota` se mandan **sin** `due_id` ni nombre cuando el rol efectivo no es manager, agrupados por día.
- [ ] **Step 4: Frontend.** Reemplazar el número grande por los dos bolsillos (cuenta y efectivo), el tránsito debajo en gris y con otra tipografía, y la fecha de corte. Si `transito_cents` es `null`, mostrar un guion y la leyenda de que no se pudo leer — **nunca 0**. Las acciones (transferir, ingreso, ajuste) solo para el manager, botones de 44px.
- [ ] **Step 5: Las cadenas nuevas en los cuatro `lang/*.json`.**
- [ ] **Step 6: Correr toda la suite.** Run: `/usr/local/bin/php vendor/bin/pest`
- [ ] **Step 7: Commit** — `git commit -m "Los dos bolsillos reemplazan a la caja vieja en la pantalla"`

---

## Después del plan (no son tareas de código)

- `php artisan app:saldo-inicial 225 0 go` en producción, el día que se pone en marcha.
- Crear la suscripción al webhook de payouts en el panel de Mollie (`payout.completed`, `payout.failed`).
- **Decidir con el dueño el bolsillo por defecto del gasto.** El plan deja "efectivo"; si la mayoría de los gastos salen de la cuenta, hay que darlo vuelta antes de que se cargue el primero.
- Deuda conocida: el fallback a Stripe en `pay()`/`subscribe()` sigue existiendo y sus payouts no están modelados. En producción hoy los 29 pagos son todos de Mollie, así que no hay riesgo real, pero conviene apagarlo.
