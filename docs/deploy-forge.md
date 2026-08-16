# Deploy en Laravel Forge

Runbook para llevar la app a producción. Stack objetivo: Forge + EC2, PHP 8.3, MySQL 8.

## 0. Repo en GitHub (una sola vez)

Forge deploya desde un proveedor Git. Crear un repo privado y pushear:

```bash
# en github.com: New repository → asnewcastle-app (privado), sin README
git remote add origin git@github.com:TU_USUARIO/asnewcastle-app.git
git push -u origin master
```

## 1. Servidor (una sola vez)

1. Forge → conectar el proveedor (AWS para EC2, o Hetzner/DO que son más baratos y simples).
2. **Create Server** → App Server:
   - Región: **Frankfurt** (eu-central-1, lo más cerca de Bucarest)
   - PHP **8.3** · Base de datos **MySQL 8**
   - Tamaño: para un club alcanza el más chico (2GB RAM)
3. Al crearlo, Forge configura nginx, PHP-FPM, MySQL y firewall solo.

## 2. Sitio

1. **New Site** → dominio real (ej. `app.asnewcastle.ro`) · Project type: Laravel · crear DB `asnewcastle`.
2. **Git Repository** → conectar GitHub → `TU_USUARIO/asnewcastle-app`, branch `master`.
3. **Deploy Script** — reemplazar por:

Los sitios nuevos usan **zero-downtime deployments** (no se puede desactivar): cada deploy
clona el código en `releases/XXX` y activa con un symlink. El script DEBE incluir los macros
de Forge — sin `$CREATE_RELEASE()` el release no existe y el deploy revienta:

```bash
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci --no-audit
npm run build

$FORGE_PHP artisan storage:link
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
```

⚠️ Además, en Settings → Deployments → **Shared paths** agregar `storage`: sin eso,
las fotos del chat se pierden en cada deploy (cada release es una carpeta nueva).

4. En el DNS del dominio: registro A apuntando a la IP del server.
5. **SSL → LetsEncrypt** (un click). Sin HTTPS no hay PWA ni webhooks.

## 3. Environment (pestaña Environment del sitio)

Partir del `.env.example` y completar:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.asnewcastle.ro
APP_TIMEZONE=Europe/Bucharest

DB_* → los completa Forge al crear la DB

SESSION_LIFETIME=129600
QUEUE_CONNECTION=database

# Twilio (cuando esté el sender aprobado)
TWILIO_ACCOUNT_SID=...
TWILIO_AUTH_TOKEN=...
TWILIO_WHATSAPP_FROM=+40...
TWILIO_OTP_TEMPLATE_SID=HX...
TWILIO_EVENT_TEMPLATE_SID=HX...
TWILIO_DUES_TEMPLATE_SID=HX...
OTP_CHANNEL=twilio
WHATSAPP_CHANNEL=twilio

# Stripe (test primero; live cuando se active la cuenta)
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...   ← del endpoint del paso 5
STRIPE_APPLICATION_FEE_BPS=0

SEED_MANAGER_PHONE=+40750471080
```

## 4. Worker y scheduler (una sola vez)

- **Queue → New Worker**: connection `database`, queue `default`, sin timeout raro. (Manda las convocatorias de WhatsApp.)
- **Scheduler**: activar el cron de Forge (`schedule:run` cada minuto). Corre: recordatorios 24hs,
  cuotas mensuales, aviso de vencimiento, apertura/cierre de figura y el scraper del clasament.

## 5. Webhooks (una sola vez, después del primer deploy)

- **Twilio** → WhatsApp sender → inbound webhook:
  `https://app.asnewcastle.ro/webhooks/twilio` (POST)
- **Stripe** → Developers → Webhooks → Add endpoint:
  `https://app.asnewcastle.ro/webhooks/stripe`
  ⚠️ Marcar **"Listen to events on Connected accounts"** (los cargos son directos en la cuenta del club)
  Evento: `checkout.session.completed`
  → copiar el `whsec_...` al env como `STRIPE_WEBHOOK_SECRET` y redeployar.

## 6. Primer deploy

1. **Deploy Now**.
2. En la consola del sitio (Forge → Commands):
   ```
   php artisan db:seed --force
   ```
   Crea el club y al presidente como delegado (`SEED_MANAGER_PHONE`).
3. Entrar con el teléfono real → código por WhatsApp → listo.
4. Activar **Quick Deploy** para que cada push a master deploye solo.

## Notas

- El `stripe_account_id` de test (acct_...) NO sirve en live: cuando pasen las claves a live,
  vaciar `clubs.stripe_account_id` y rehacer el onboarding Express desde la app.
- La DB local de desarrollo es MySQL 5.7 (MAMP); producción es MySQL 8 — no usar features exclusivas.
- Los tests corren en CI/local, no en el server.
