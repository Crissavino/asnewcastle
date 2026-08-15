<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AltaController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\CuotaController;
use App\Http\Controllers\MvpVoteController;
use App\Http\Controllers\PlayerRatingController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\StripeConnectController;
use App\Http\Controllers\VestuarioController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Webhooks\TwilioWebhookController;
use App\Http\Middleware\VerifyTwilioSignature;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\SetActiveClub;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/agenda');

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

// Link de invitación firmado: funciona logueado o no
Route::get('/invitacion/{club:slug}', [InviteController::class, 'accept'])
    ->middleware('signed')
    ->name('invitacion');

Route::middleware('auth')->group(function () {
    Route::post('/salir', [OtpController::class, 'logout'])->name('salir');

    // Sin club: usuario verificado que no es member de ningún club
    Route::get('/sin-club', fn () => Inertia::render('Auth/SinClub'))->name('sin-club');

    Route::middleware(SetActiveClub::class)->group(function () {
        // Alta: el wizard de 5 pasos, fuera del chequeo de perfil completo
        Route::get('/alta', [AltaController::class, 'show'])->name('alta');
        Route::post('/alta', [AltaController::class, 'store'])->name('alta.guardar');

        Route::middleware(EnsureProfileComplete::class)->group(function () {
            Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
            Route::post('/eventos', [AgendaController::class, 'store'])->name('eventos.crear');
            Route::post('/eventos/{event}/recordar', [AgendaController::class, 'remind'])->name('eventos.recordar');
            Route::post('/eventos/{event}/asistencia', [AttendanceController::class, 'store'])->name('asistencia');
            Route::get('/tabla', fn () => Inertia::render('Tabla', [
                'standings' => app(\App\Support\CurrentClub::class)->club()->standings_json,
            ]))->name('tabla');
            Route::get('/vestuario', [VestuarioController::class, 'show'])->name('vestuario');
            Route::post('/vestuario', [VestuarioController::class, 'store'])->name('vestuario.enviar');
            Route::post('/eventos/{event}/figura', [MvpVoteController::class, 'store'])->name('figura.votar');
            Route::post('/eventos/{event}/puntaje', [PlayerRatingController::class, 'store'])->name('puntaje.votar');
            Route::get('/cuota', [CuotaController::class, 'show'])->name('cuota');
            Route::post('/cuota/{due}/pagar', [CuotaController::class, 'pay'])->name('cuota.pagar');
            Route::post('/cuota/reclamar', [CuotaController::class, 'claim'])->name('cuota.reclamar');
            Route::post('/stripe/onboarding', [StripeConnectController::class, 'start'])->name('stripe.onboarding');
            Route::get('/stripe/retorno', [StripeConnectController::class, 'back'])->name('stripe.retorno');
            Route::get('/perfil', [PerfilController::class, 'show'])->name('perfil');

            Route::post('/invitaciones', [InviteController::class, 'create'])->name('invitacion.crear');
        });
    });
});
