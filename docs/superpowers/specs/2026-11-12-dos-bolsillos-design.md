# Diseño — Dos bolsillos: que el saldo de la app sea la plata que hay

**Fecha:** 12.11.2026 · **Pedido por:** el presidente del club (dueño del proyecto)
**Estado:** APROBADO por el dueño el 12.11.2026

---

## Intención

Antes de hacer un gasto, el presidente necesita saber cuánta plata hay **de verdad**, y
confiar en ese número. Hoy no puede.

La app muestra una "caja" que es `cobrado − gastado` acumulado desde siempre. Ese número
mezcla dos cosas que no son la misma: plata disponible y plata que ya se movió; y mezcla el
banco con el efectivo. No está mal calculado — está midiendo otra cosa.

El objetivo es que **cuenta + efectivo = la plata que el club tiene**, y que el saldo de la
cuenta coincida con el extracto del banco.

---

## El modelo: dos bolsillos y un tránsito

### Cuenta (el banco del club)

- **Entra:** los payouts de Mollie; transferencias del jugador directo al IBAN del club
  (flujo que ya existe, lo marca el manager a mano); ingresos que no son cuota.
- **Sale:** transferencias a una persona para que pague algo; gastos pagados desde la cuenta.

### Efectivo

Un solo bolsillo del club, aunque físicamente lo tenga una persona distinta cada vez
(**decisión del dueño:** no se modela quién lo tiene).

- **Entra:** cuotas cobradas a mano marcadas "en cash"; transferencias desde la cuenta.
- **Sale:** gastos pagados en mano.

### En tránsito (no es un bolsillo)

Plata cobrada por Mollie cuyo payout todavía no llegó. **Se muestra aparte y nunca se suma
al disponible.**

**Decisión del dueño:** el número se lee del saldo real de Mollie (`Balance` /
`BalanceTransaction` del SDK), no se deriva de nuestros datos. Motivo: nuestros `payments`
son brutos y los payouts son netos de comisión, así que cualquier cálculo propio queda
inflado para siempre con la acumulación de fees.

Consecuencia: hace falta el permiso `balances.read` además de `payouts.read`. A cambio, el
número es exacto y **no hace falta anclar un valor inicial de tránsito**: se lee.

---

## Los seis movimientos

Todo movimiento declara bolsillo y signo. No hay ninguno que no lo haga.

| # | Movimiento | Efecto | Quién lo origina |
|---|---|---|---|
| 1 | Payout de Mollie | cuenta **+** (monto neto) | automático |
| 2 | Cuota cobrada a mano | cash **+** o cuenta **+** | el manager elige al marcarla |
| 3 | Gasto | cash **−** o cuenta **−** | el manager elige al cargarlo |
| 4 | Transferencia entre bolsillos | cuenta **−** y cash **+** (o al revés) | el manager |
| 5 | Ingreso que no es cuota | cualquiera **+** | el manager |
| 6 | Ajuste | cualquiera, **con nota obligatoria** | el manager |

Más un séptimo que nace solo: **comisión de Mollie**, la diferencia entre lo cobrado y lo
que entró en el payout. Se registra como gasto propio para que se vea cuánto cuesta cobrar.

### Regla dura: la transferencia no es un gasto

Lo de Sergio a Andres saca plata de la cuenta y la mete al efectivo. **El gasto recién
existe cuando Andres paga la cancha.** Si se registran las dos cosas como gasto, el club
gastó el doble.

Es la regla que más fácil se rompe, y hay que defenderla en la pantalla: cargar una
transferencia y cargar un gasto tienen que ser dos acciones visiblemente distintas.

### El movimiento 5 cierra un hueco viejo

Hasta hoy no se puede registrar plata que no sea una cuota: ni sponsor, ni rifa, ni el
patrimonio inicial de 600 lei, ni un aporte del presidente para tapar un mes. Eso hace que
la caja parezca sana cuando en realidad alguien la está sosteniendo por abajo. El
movimiento 5 lo resuelve, y evita que todo eso termine entrando como "ajuste" — que debe
seguir siendo excepcional y sospechoso.

---

## Saldo inicial: línea en la arena

**Decisión del dueño:** no se reconstruye el histórico.

Al poner esto en marcha, un comando artisan one-off crea los movimientos `saldo_inicial`
con la fecha del día:

- cuenta = **225 lei** (dato del dueño al 12.11.2026)
- efectivo = **0** (decisión del dueño, 12.11.2026)

> **Consecuencia conocida.** La transferencia de Sergio a Andres ya salió de la cuenta (los
> 225 son el saldo posterior), así que esa plata no entra a ningún bolsillo. Cuando Andres
> pague la cancha del viernes, el efectivo va a quedar en negativo por ese monto. Se corrige
> con un movimiento de ajuste. El dueño lo sabe y eligió arrancar igual en 0.
- tránsito = no hace falta, se lee de Mollie

Nada anterior se recalcula. Los gastos ya cargados quedan sin bolsillo y fuera de los saldos.

---

## Qué desaparece de la pantalla

**Decisión del dueño:** los dos bolsillos **reemplazan** al saldo actual.

- Se va: `resumen.balance_cents` (cobrado histórico − gastado histórico), el número grande
  de `Cuota.jsx`.
- Se queda: cobrado vs adeudado y la lista de deudores. Es la palanca de cobranza y no
  tiene nada que ver con el saldo disponible.

Esto es lo que evita el peor resultado posible: dos números distintos en la misma pantalla
el mismo día.

---

## Goals

- Ver, antes de gastar, cuánto hay en cada bolsillo, y que el número sea confiable.
- Que el saldo "cuenta" coincida con el extracto bancario.
- Que la plata entre sola cuando Mollie paga.
- Que el historial de movimientos explique cómo se llegó a ese saldo.
- Que se vea cuánto cuesta cobrar por Mollie.

## Non-goals

- No reconcilia contra el banco automáticamente: el extracto se mira a ojo.
- No modela quién tiene el efectivo.
- No reconstruye nada anterior a la línea en la arena.
- No es contabilidad: sin asientos, sin períodos, sin cierre.
- No modela Stripe (ver riesgos).

## Anti-goals (esto fracasa aunque funcione)

- **Que haya que cargar el mismo hecho dos veces.** Si el manager tiene que registrar un
  gasto *y además* su movimiento, en dos semanas el saldo está mal y nadie vuelve a confiar.
  Cargar un gasto escribe su movimiento solo.
- **Fingir una precisión que no se tiene.** El saldo se muestra con fecha de corte.
- **Que un jugador vea un número y otro vea otro.**

---

## Datos

```
movements
  id, club_id
  pot: cuenta | cash
  kind: payout | cuota | gasto | transferencia | ingreso | ajuste | fee | saldo_inicial
  amount_cents (con signo: + entra, − sale)
  occurred_on (date)
  group_uuid (nullable)   -- ata las dos filas de una transferencia
  reverses_movement_id (nullable)  -- contra-asiento
  expense_id, due_id, mollie_payout_id (nullable)
  note (nullable; obligatoria si kind = ajuste)
  created_by_member_id (nullable si es automático)
  timestamps

mollie_payouts
  id, club_id
  mollie_payout_id (unique)   -- idempotencia del webhook
  amount_cents, status, paid_at
  timestamps
```

Y dos columnas: `expenses.pot` y el `pot` del pago manual de cuota.

**Nota de scope:** CLAUDE.md pide no pasar de ~8 tablas y avisar si el diseño se va. Esto
suma dos. Sin un libro de movimientos los saldos no pueden cerrar: cualquier alternativa
termina siendo el mismo libro, derivado y más frágil.

---

## Decisiones que tomé yo (no las habló el dueño)

- **Solo el manager registra; los jugadores ven los dos saldos.** Coherente con la regla
  existente: la caja es visible para todo el plantel, las acciones son del manager.
- **Los jugadores ven el historial, pero los movimientos de cuota les aparecen agregados**,
  sin nombre ni monto individual. Si no, el historial filtraría quién es becado y quién
  tiene cuota personalizada, que CLAUDE.md declara privado del manager.
- **Corregir no borra: se hace un contra-asiento visible** (`reverses_movement_id`). Borrar
  el movimiento de un gasto borrado dejaría el historial sin poder explicar el saldo.
- **Un ajuste se carga como saldo objetivo, no como delta.** El manager escribe "el banco
  dice 180" y el sistema calcula la diferencia: es lo que va a hacer con el extracto delante.
- **Un gasto mayor al efectivo disponible avisa, no bloquea.** La app no le puede decir al
  club que no gaste.
- **Si el webhook de payouts no está disponible, plan B es un chequeo diario** en el
  scheduler que ya existe. Nunca carga manual de payouts.
- **El bolsillo por defecto al cargar un gasto es "efectivo".** ⚠️ A confirmar con el dueño:
  si la mayoría de los gastos reales salen de la cuenta, este default ensucia el saldo en el
  sentido contrario al que el diseño quiere proteger.
- **El saldo se muestra con fecha de corte**, no como si fuera en vivo.

## Decisiones del constructor (ya acordadas)

- La transferencia entre bolsillos son **dos filas de `movements` atadas por `group_uuid`**:
  `pot` es una columna sola y no admite un movimiento de dos bolsillos.
- La pantalla es una **sección nueva dentro de `/cuota`**, reutilizando el layout existente,
  no una ruta aparte.

## Lo que queda al constructor

- La forma exacta de la sección (mobile-first a 380px, estética del prototipo).
- Si la suscripción al webhook se hace por comando artisan o a mano en el panel de Mollie.
- Cómo se presenta "en tránsito" sin que se lea como disponible.

---

## Riesgos y cosas a verificar antes de codear

1. **Permisos `payouts.read` y `balances.read`.** Los webhooks de payout son los de *nueva
   generación* (`docs.mollie.com/reference/webhooks-new`), un mecanismo distinto del webhook
   de pagos que ya está andando. Confirmar que la credencial del club los habilita, y quién
   entra al panel de Mollie para crear la suscripción.
2. **El SDK (mollie-api-php 3.13.2)** tiene `WebhookEndpointCollection`, `Payout` y
   `Balance`, pero no constantes para los tipos de evento: van como string
   (`payout.completed`, `payout.failed`, …).
3. **Payouts fallidos o cancelados.** Mollie documenta que una cancelación posterior al
   envío llega como `payout.failed`, no `payout.canceled`. Manejar los dos y contra-asentar.
4. **Moneda: RON** (confirmado por el dueño). El payout entra en lei y coincide directo con
   el extracto; no hay diferencia de cambio que modelar.
5. **La cuenta de Mollie es propia del club, no Connect** (`config/services.php:58`): los
   payouts van directo a su banco, no hay intermediación que modelar.
6. **Stripe queda fuera.** El código todavía tiene `StripeGateway` y el fallback en
   `pay()`/`subscribe()`. En producción los 29 pagos existentes son todos de Mollie, así que
   hoy no hay riesgo real; pero si alguna vez entra un pago por Stripe, su payout no está
   modelado y el saldo quedaría corto sin que nadie lo note. Conviene apagar el fallback como
   parte de esta tarea o dejarlo escrito como deuda conocida.
7. **El riesgo real no es técnico, es de disciplina.** Si los gastos se siguen cargando a
   medias —hoy hay 9 partidos jugados y 2 árbitros cargados— el saldo va a divergir del
   banco igual, y va a parecer que falla el sistema. Y esto agrega un campo obligatorio más
   (`pot`) al formulario de gasto.
