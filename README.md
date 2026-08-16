<p align="center">
  <img src="public/img/crest.png" alt="A.S New Castle" width="120">
</p>

<h1 align="center">A.S New Castle — Gestión del club</h1>

<p align="center">
  App de gestión para clubes de fútbol amateur. El cliente cero es la
  <strong>Asociația Sportivă New Castle</strong> (Voluntari, Ilfov — Liga a V-a).
</p>

---

## Qué resuelve

Todo lo que hoy se resuelve mal en un grupo de WhatsApp:

1. **Nadie sabe quién va al partido** → convocatorias con Voy / Duda / No voy, contador en vivo y recordatorio automático a los que no contestaron.
2. **Nadie sabe quién pagó la cuota** → cuotas mensuales con pago online (Stripe), efectivo marcado a mano, deudores a la vista y caja transparente con gastos.
3. **La información se pierde** → horario, cancha y qué casaca llevar, siempre en el mismo lugar.

No es una red social. Es una herramienta operativa semanal.

## Las cinco pestañas

| Pestaña | Qué hace |
|---|---|
| **Agenda** | Partidos y entrenamientos con confirmación de asistencia, convocatoria copiable, edición/cancelación con aviso, resultados y racha |
| **Tabla** | Clasament de la liga (scrapeado de frf-ajf.ro a diario) + campaña propia: posición, racha V-E-D y próximo rival |
| **Vestuario** | Chat del equipo con fotos, mensajes del sistema, votación de figura post-partido y calificación ternaria anónima |
| **Cuota** | Pago online en la cuenta del club (Stripe Connect), efectivo/condonaciones, estado del plantel y caja con gastos por categoría |
| **Perfil** | Ficha del jugador (editable), disponibilidad por día, plantel, estadísticas de temporada y administración del club |

Además: **login por WhatsApp** (OTP, sin contraseña ni email), **tres idiomas** (inglés, rumano y español rioplatense), **PWA instalable**, y responsive real: app en el teléfono, riel en tablet, web con sidebar en escritorio.

## Stack

- **Laravel 12** (PHP 8.3) + **Inertia 2** + **React 18**
- MySQL 8 · CSS plano con variables (sin Tailwind) — el diseño sale de `docs/prototype.jsx`
- **Twilio** (WhatsApp Business): OTP, convocatorias con botones, recordatorios
- **Stripe Connect Express**: cada club cobra en **su propia cuenta** — la plata nunca pasa por la plataforma
- Deploy: Laravel Forge (ver [`docs/deploy-forge.md`](docs/deploy-forge.md))

## Decisiones de arquitectura

- **Aislamiento por club**: todo query filtra por `club_id` vía global scope + middleware que resuelve el club activo. Hay tests que verifican que un club no puede leer datos de otro.
- **Roles**: `player` y `manager` — el manager también juega. Se administran desde la app.
- **Canales intercambiables**: los envíos de WhatsApp salen por Twilio en producción y por el log en desarrollo (`OTP_CHANNEL=log`), sin tocar código.
- **Webhooks firmados e idempotentes**: Twilio (HMAC-SHA1) y Stripe (firma + unicidad por `payment_intent_id`). El endpoint de Stripe escucha eventos de **cuentas conectadas**.
- **Sin registro abierto**: se entra solo con link de invitación firmado que genera el delegado.
- **i18n key-value**: `lang/{en,ro,es}.json` compartido a React por props de Inertia; los datos en DB son neutrales al idioma.

## Desarrollo local

Requisitos: PHP 8.3+, Composer, Node 20+, MySQL.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# completar en .env: DB_*, SEED_MANAGER_PHONE (tu número E.164)

php artisan migrate --seed          # club + delegado
php artisan db:seed --class=DemoSeeder   # opcional: datos de demo para ver la app viva

npm run dev
php artisan serve
```

- El código OTP aparece en `storage/logs/laravel.log` (`OTP_CHANNEL=log`).
- Pagos en local: `stripe listen --forward-connect-to 127.0.0.1:8000/webhooks/stripe`
  (⚠️ `--forward-connect-to`, no `--forward-to`: los cargos son directos en la cuenta conectada).
- Tarjeta de prueba: `4242 4242 4242 4242`.

## Tests

```bash
php artisan test        # Pest — cubre OTP, aislamiento por club, webhooks, dorsales, cuotas, figura...
```

## Tareas programadas

| Comando | Cuándo | Qué hace |
|---|---|---|
| `eventos:recordar` | cada hora | Recordatorio 24hs antes, solo a los que no contestaron |
| `cuotas:generar` | día 1, 06:00 | Genera las cuotas del mes para los miembros activos |
| `cuotas:avisar` | diario 09:00 | El día del vencimiento publica los deudores en el vestuario |
| `figura:abrir` / `figura:cerrar` | cada hora | Abre la votación post-partido y anuncia al ganador a las 48hs |
| `tabla:importar` | diario 07:00 | Scrapea el clasament de la AJF y actualiza la tabla |

## Documentación

- [`CLAUDE.md`](CLAUDE.md) — reglas del proyecto, modelo de datos y fases
- [`docs/prototype.jsx`](docs/prototype.jsx) — el prototipo aprobado, fuente de verdad del diseño
- [`docs/deploy-forge.md`](docs/deploy-forge.md) — runbook de deploy en producción
