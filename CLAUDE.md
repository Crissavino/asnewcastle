# CLAUDE.md — Plataforma de clubes

## Qué estamos construyendo

Una app de gestión para clubes de fútbol amateur. El cliente cero es **A.S New Castle**, asociación deportiva registrada en Voluntari, Ilfov (Rumanía), que juega Liga a V-a. El presidente del club es el dueño de este proyecto.

El producto resuelve tres dolores concretos que hoy se resuelven mal en un grupo de WhatsApp:

1. **Nadie sabe quién va al partido.** El delegado manda "confirmen" y cuenta a mano entre 60 mensajes.
2. **Nadie sabe quién pagó la cuota.** Se lleva en la cabeza o en un papel.
3. **La información se pierde.** Horario, cancha, qué casaca llevar.

No es una red social ni un crowdfunding. Es una herramienta operativa semanal.

## Datos legales del club (cliente cero)

Documentación completa en `~/Desktop/Desktop_Mac/projects/Club de Futbol/Documentos/Final Documentation.pdf`
(certificado de inscripción, încheiere judecătorească y extracto del Registrul Special).

- Denominación legal: **ASOCIAȚIA SPORTIVĂ NEW CASTLE** (persona jurídica sin fines de lucro, O.G. 26/2000)
- Inscripción: Registrul Special **115PJ / 14.10.2025**, Judecătoria Buftea (dosar 26089/94/2025, încheiere 10529 del 30.07.2025)
- Sede: Str. Emil Racoviță nr. 27C, parter, ap. 2, Oraș Voluntari, județul Ilfov
- Duración: indeterminada · Patrimonio inicial: 600 LEI
- Conducción: Cristian Maximiliano Savino (**presidente**, representante legal), Sergio Felipe Quiroga Gualteros (vicepresidente), Fabian Andres Rodriguez Rodriguez (secretario)
- Objeto: promoción y desarrollo del fútbol (Divizia a 5-a, seniors y juniors), afiliación a la FRF
- Ojo: el CUI/CIF fiscal no figura en este PDF (se tramita en ANAF aparte) — hace falta para la activación en vivo de Stripe

Estos son los datos que van en el onboarding real de Stripe (tipo: non-profit **constituită**) y en cualquier formulario legal.

## Reglas duras

Estas no se negocian. Si algo en una tarea las contradice, pará y preguntá.

- **Todo query filtra por `club_id`.** Sin excepción. Un usuario puede pertenecer a varios clubes. Usar un global scope + middleware que resuelve el club activo, y tests que verifiquen que un usuario del club A no puede leer datos del club B.
- **i18n key-value desde el día uno.** Inglés (obligatorio, es el fallback), rumano y español. Diccionarios en `lang/{en,ro,es}.json`, compartidos a React por props de Inertia con el helper `t()` de `resources/js/i18n.js`. Toda cadena de UI nueva entra en los tres archivos. El español es rioplatense: "vos", no "tú". La preferencia se guarda en `users.locale`; primera visita se detecta por `Accept-Language`.
- **Mobile-first.** Diseñar para 380px; todo lo tocable, mínimo 44px de alto. En escritorio NO hay layout alternativo: es la misma columna mobile centrada sobre un "escenario" del club (fondo oscuro con branding, hovers). Ninguna funcionalidad puede depender del viewport ancho.
- **Nada que dependa del navegador.** Esta app se va a envolver en Capacitor. No usar `window.open`, ni navegación por URL bar, ni nada que asuma pestañas o barra de direcciones.
- **Sesiones largas.** Un jugador se loguea una vez y no vuelve a loguearse en meses.
- **No inventar features.** Si algo no está en las fases de abajo, no va.

## Stack

- Laravel 12, PHP 8.3
- Inertia 2 + React 18
- MySQL 8
- Tailwind no — el prototipo usa CSS plano con variables. Portarlo tal cual a `resources/css/app.css`.
- Twilio (WhatsApp Business API) para OTP y avisos
- Stripe Connect Express para cuotas
- Deploy: Laravel Forge sobre EC2
- PWA en fase 1, Capacitor recién después de que el club la use dos semanas

## Referencia visual

En `/docs/prototype.jsx` está el prototipo aprobado. **Es la fuente de verdad del diseño.** Copiar de ahí:

- La paleta: rojo `#D22233` (casaca titular), celeste `#8AD4D8` (suplente), negro `#121212`, papel `#F1F2EF`, línea `#DBDDD8`, gris texto `#767B77`
- Las tipografías: Archivo Black para títulos, Archivo para cuerpo, Rubik para números
- El componente `Kit` (la camiseta con el dorsal, con variante `home` y `away`)
- El header con pinstripes de la casaca
- El escudo: en producción va como archivo estático en `/public/img/crest.png`, **no** embebido en base64 como en el prototipo

El escudo del club no se modifica, no se recolorea y no se redibuja.

## Modelo de datos

Ocho tablas. Si te salen quince, algo se fue de scope.

```
clubs
  id, name, slug, city, league, crest_path
  stripe_account_id, stripe_onboarded_at
  monthly_fee_cents, currency (default RON)
  timestamps

users
  id, name, phone (E.164, unique), phone_verified_at
  timestamps
  -- sin password, sin email obligatorio

members            -- pivot user <-> club
  id, club_id, user_id
  role: player | manager
  shirt_number (unique por club, nullable)
  position: ARQ | DEF | MED | DEL (nullable)
  preferred_foot (nullable)
  availability json    -- slots elegidos en el alta
  joined_at, left_at (nullable)
  timestamps

events
  id, club_id, created_by_member_id
  kind: match | training
  opponent (nullable), is_home (bool)
  starts_at (datetime), venue
  kit: home | away
  notes (nullable)
  notified_at (nullable)
  timestamps

attendances
  id, event_id, member_id
  status: in | maybe | out
  responded_at, source: app | whatsapp
  unique(event_id, member_id)

messages           -- chat del vestuario
  id, club_id, member_id (nullable si es del sistema)
  body, is_system (bool)
  timestamps

dues
  id, club_id, member_id
  period (date, día 1 del mes)
  amount_cents, status: pending | paid | waived
  due_date
  unique(club_id, member_id, period)

payments
  id, due_id, stripe_payment_intent_id (unique)
  amount_cents, application_fee_cents
  status, paid_at
  timestamps
```

Notas de diseño:

- `standings` no es tabla. La tabla de posiciones en v1 es un JSON en `clubs.standings_json`, editable por el manager. No vale la pena modelar una liga entera para mostrar ocho filas.
- `attendances` no tiene soft delete. Si alguien cambia de opinión, se actualiza la fila.
- Los montos siempre en centavos, enteros. Nunca float.

## Fases

Una fase por sesión de trabajo. Antes de escribir código en cada fase: proponé el plan, esperá aprobación. Al final de cada fase: tests verdes y commit.

### Fase 1 — Auth por WhatsApp

El jugador entra con su número de teléfono y un código de 6 dígitos que le llega por WhatsApp. No hay contraseña. No hay email.

- Pantalla de teléfono con selector de país (default RO, pero el plantel tiene argentinos y colombianos — soportar AR, CO, IT, ES)
- Envío del OTP con una plantilla de Twilio categoría *authentication*
- Verificación, creación de `user` si no existe, sesión "remember me" de 90 días
- Rate limit: máximo 3 códigos por número por hora, código válido 10 minutos, un solo uso
- Si el número no está en ningún club: pantalla "pedile el link al delegado". No hay registro abierto.
- Invitación: el manager genera un link con token firmado que asocia el número al club

**Criterio de aceptación:** puedo entrar con mi número real y recibir el código por WhatsApp.

### Fase 2 — Alta del jugador y perfil

El wizard de 5 pasos del prototipo: nombre, puesto, perfil hábil, dorsal, disponibilidad.

- Los dorsales tomados se muestran tachados y no se pueden elegir (validar también en el servidor, con constraint de unicidad)
- Al terminar se crea el `member` y se entra a la app
- Pantalla de perfil: ficha, disponibilidad, plantel del club

**Criterio de aceptación:** dos jugadores no pueden quedarse con el mismo dorsal ni haciendo la petición a mano.

### Fase 3 — Eventos y asistencia

El núcleo del producto.

- Vista jugador: lista de próximos eventos con Voy / Duda / No voy. Contador de confirmados. Camisetas en el kit del evento.
- Vista manager: crear evento, ver la convocatoria en texto plano copiable (`10 Cristian Savino · 5 Sergio Quiroga · ...`), ver quién no contestó, botón para recordarles
- Al crear un evento se dispara la convocatoria por WhatsApp a todo el plantel
- La respuesta del botón de WhatsApp entra por webhook y actualiza `attendances` con `source: whatsapp`
- Recordatorio automático 24hs antes solo a los que no respondieron (job programado)

**Criterio de aceptación:** creo un evento desde la app, me llega el WhatsApp, toco "Voy", y el contador sube sin abrir la app.

### Fase 4 — Cuotas con Stripe Connect

- Onboarding del club a Stripe Connect Express desde la app (el manager completa el flujo de Stripe)
- Job mensual que genera las `dues` del período para todos los miembros activos
- Checkout del jugador con `payment_intent` sobre la cuenta conectada, con `application_fee_amount` para la plataforma
- Webhook de Stripe con verificación de firma e idempotencia por `payment_intent_id`
- Vista manager: caja del mes, quiénes deben, botón para reclamarles por WhatsApp

**Criterio de aceptación:** un pago real de prueba en modo test acredita en la cuenta conectada del club, no en la de la plataforma.

Ojo con esto: **la plata nunca pasa por una cuenta de la plataforma.** Cada club cobra en su propia cuenta conectada. Si el diseño te lleva a que los fondos toquen una cuenta central, pará y avisá — eso convierte a la plataforma en intermediario de pagos y cambia el marco legal.

### Fase 5 — Vestuario

Chat simple del equipo.

- Polling cada 8 segundos. **No** WebSockets, no Reverb, no Echo en v1.
- Mensajes del sistema automáticos cuando alguien confirma o se crea un evento
- Fotos en el chat (disco público, sin galería aparte). Sin reacciones, sin hilos, sin edición
- Post-partido (2hs después, ventana de 48hs): votación de figura entre los que fueron,
  y calificación ternaria anónima de cada compañero (le costó / cumplió / crack —
  la más baja nunca es hiriente). Sin puntajes numéricos ni estadísticas acumuladas.

## Lo que NO va en v1

Escrito para que no aparezca por inercia:

- Crowdfunding y donaciones
- Notificaciones push web
- Estadísticas de jugadores (goles, tarjetas, minutos)
- Formación táctica / pizarra
- WebSockets
- Panel de administración de la plataforma (Filament u otro)
- Registro público de clubes — los clubes se dan de alta a mano por ahora
- Modo oscuro

## Seguridad

- Policies de Laravel en todo. Un `player` no puede crear eventos, editar la tabla ni ver la caja del club.
- Verificación de firma en los webhooks de Stripe **y** de Twilio.
- Idempotencia en todo handler de webhook.
- Los números de teléfono nunca aparecen en URLs ni en respuestas de API a otros jugadores.
- Rate limit en el endpoint de OTP.

## Cómo quiero trabajar

- Antes de codear una fase: mostrame el plan y las migraciones propuestas. Espero aprobación.
- Tests con Pest. Como mínimo: feature test del aislamiento por `club_id`, del flujo de OTP, del webhook de Stripe y del webhook de Twilio.
- Commits chicos, mensaje en español, uno por unidad de trabajo terminada.
- Si algo del prototipo no se puede replicar tal cual, decímelo en vez de improvisar una alternativa.
- Si una decisión tiene consecuencias que no están en este documento, pará y preguntá antes de avanzar.
