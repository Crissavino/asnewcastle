<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AltaController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\CuotaController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\MessageTranslationController;
use App\Http\Controllers\MvpVoteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlayerRatingController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Controllers\PushTokenController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\StripeConnectController;
use App\Http\Controllers\TablaController;
use App\Http\Controllers\VestuarioController;
use App\Http\Controllers\ViewModeController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Webhooks\TwilioWebhookController;
use App\Http\Middleware\VerifyTwilioSignature;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\SetActiveClub;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/agenda');

// Política de privacidad: pública (Google Play la exige y la revisa sin login)
Route::view('/privacidad', 'privacidad')->name('privacidad');

// Puente de retorno del checkout de Stripe en la app nativa: reabre la app por
// deep link (asnewcastle://). Pública: el navegador del sistema no tiene sesión.
Route::view('/pago/volver', 'pago-volver')->name('pago.volver');

// Idioma: se puede cambiar logueado o no (pantalla de entrada)
Route::post('/idioma', [OtpController::class, 'setLocale'])->name('idioma');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [OtpController::class, 'showPhone'])->name('entrar');
    Route::post('/otp', [OtpController::class, 'sendCode'])
        ->middleware('throttle:10,1')
        ->name('otp.enviar');
    Route::get('/codigo', [OtpController::class, 'showCode'])->name('codigo');
    Route::post('/codigo/reenviar', [OtpController::class, 'resend'])
        ->middleware('throttle:10,1')
        ->name('otp.reenviar');
    Route::post('/codigo', [OtpController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('otp.verificar');
});

// Webhook de Twilio: respuestas de los botones de WhatsApp
Route::post('/webhooks/twilio', TwilioWebhookController::class)
    ->middleware(VerifyTwilioSignature::class)
    ->name('webhooks.twilio');

// Webhook de Stripe: pagos de cuotas en las cuentas conectadas
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

// Formulario público de legitimación: solo por link firmado del manager.
// Sin login y sin acceso a nada más; la sesión ata la ficha al navegador.
Route::get('/legitimacion/publica/{club:slug}', [PublicRegistrationController::class, 'show'])
    ->middleware('signed')
    ->name('legitimacion.publica');
Route::post('/legitimacion/publica', [PublicRegistrationController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('legitimacion.publica.guardar');

// Link de invitación firmado: funciona logueado o no
Route::get('/invitacion/{club:slug}', [InviteController::class, 'accept'])
    ->middleware('signed')
    ->name('invitacion');

Route::middleware('auth')->group(function () {
    Route::post('/salir', [OtpController::class, 'logout'])->name('salir');

    // Registro del token de push del dispositivo (la app nativa lo manda al
    // arrancar). Es del usuario, no del club: va fuera de SetActiveClub.
    Route::post('/push/token', [PushTokenController::class, 'store'])->name('push.token');
    Route::delete('/push/token', [PushTokenController::class, 'destroy'])->name('push.token.baja');

    // Sin club: usuario verificado que no es member de ningún club
    Route::get('/sin-club', fn () => Inertia::render('Auth/SinClub'))->name('sin-club');

    Route::middleware(SetActiveClub::class)->group(function () {
        // Alta: el wizard de 5 pasos, fuera del chequeo de perfil completo
        Route::get('/alta', [AltaController::class, 'show'])->name('alta');
        Route::post('/alta', [AltaController::class, 'store'])->name('alta.guardar');

        Route::middleware(EnsureProfileComplete::class)->group(function () {
            Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
            Route::post('/eventos', [AgendaController::class, 'store'])->name('eventos.crear');
            Route::put('/eventos/{event}', [AgendaController::class, 'update'])->name('eventos.actualizar');
            Route::post('/eventos/{event}/cancelar', [AgendaController::class, 'cancel'])->name('eventos.cancelar');
            Route::post('/eventos/{event}/resultado', [AgendaController::class, 'result'])->name('eventos.resultado');
            Route::post('/eventos/{event}/presentes', [PresenceController::class, 'store'])->name('eventos.presentes');
            Route::post('/eventos/{event}/recordar', [AgendaController::class, 'remind'])->name('eventos.recordar');
            Route::post('/eventos/{event}/asistencia', [AttendanceController::class, 'store'])->name('asistencia');
            Route::get('/tabla', [TablaController::class, 'show'])->name('tabla');
            Route::post('/notificaciones/leidas', [NotificationController::class, 'markRead'])->name('notificaciones.leidas');
            Route::get('/vestuario', [VestuarioController::class, 'show'])->name('vestuario');
            Route::post('/vestuario', [VestuarioController::class, 'store'])->name('vestuario.enviar');
            Route::post('/vestuario/{message}/traducir', [MessageTranslationController::class, 'translate'])->name('vestuario.traducir');
            Route::post('/eventos/{event}/figura', [MvpVoteController::class, 'store'])->name('figura.votar');
            Route::post('/eventos/{event}/puntaje', [PlayerRatingController::class, 'store'])->name('puntaje.votar');
            Route::get('/cuota', [CuotaController::class, 'show'])->name('cuota');
            Route::post('/cuota/{due}/pagar', [CuotaController::class, 'pay'])->name('cuota.pagar');
            Route::post('/cuota/suscribir', [CuotaController::class, 'subscribe'])->name('cuota.suscribir');
            Route::post('/plantel/{member}/suscripcion/cancelar', [CuotaController::class, 'cancelSubscription'])->name('cuota.suscripcion.cancelar');
            Route::post('/cuota/reclamar', [CuotaController::class, 'claim'])->name('cuota.reclamar');
            Route::post('/cuota/{due}/estado', [CuotaController::class, 'setStatus'])->name('cuota.estado');
            Route::patch('/cuota/config', [CuotaController::class, 'updateConfig'])->name('cuota.config');
            Route::post('/plantel/{member}/cuota', [CuotaController::class, 'setMemberFee'])->name('plantel.cuota');
            Route::post('/stripe/onboarding', [StripeConnectController::class, 'start'])->name('stripe.onboarding');
            Route::get('/stripe/retorno', [StripeConnectController::class, 'back'])->name('stripe.retorno');
            Route::get('/perfil', [PerfilController::class, 'show'])->name('perfil');
            Route::get('/estadisticas', [StatsController::class, 'own'])->name('estadisticas');
            Route::get('/plantel/{member}/estadisticas', [StatsController::class, 'member'])->name('plantel.estadisticas');
            Route::patch('/perfil', [PerfilController::class, 'update'])->name('perfil.actualizar');
            Route::post('/perfil/disponibilidad', [PerfilController::class, 'updateAvailability'])->name('perfil.disponibilidad');
            Route::post('/plantel/{member}/baja', [PerfilController::class, 'removeMember'])->name('plantel.baja');
            Route::post('/plantel/{member}/rol', [PerfilController::class, 'setRole'])->name('plantel.rol');
            Route::post('/gastos', [ExpenseController::class, 'store'])->name('gastos.crear');
            Route::delete('/gastos/{expense}', [ExpenseController::class, 'destroy'])->name('gastos.borrar');

            Route::post('/invitaciones', [InviteController::class, 'create'])->name('invitacion.crear');

            // Legitimación en la Federación (temporada 2026-27)
            Route::get('/legitimacion', [RegistrationController::class, 'show'])->name('legitimacion');
            Route::post('/legitimacion', [RegistrationController::class, 'store'])->name('legitimacion.guardar');
            Route::post('/legitimacion/recordar', [RegistrationController::class, 'remind'])->name('legitimacion.recordar');
            Route::post('/legitimacion/{registration}/enviado', [RegistrationController::class, 'markSent'])->name('legitimacion.enviado');
            Route::get('/legitimacion/{registration}/doc/{field}', [RegistrationController::class, 'doc'])->name('legitimacion.doc');
            Route::get('/legitimacion/{registration}/zip', [RegistrationController::class, 'zip'])->name('legitimacion.zip');

            // Toggle admin/jugador (solo el dueño; el controlador lo valida)
            Route::post('/ver-como', [ViewModeController::class, 'toggle'])->name('ver-como');
        });
    });
});
