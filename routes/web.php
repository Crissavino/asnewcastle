<?php

use App\Http\Controllers\AltaController;
use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\PerfilController;
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
            Route::get('/agenda', fn () => Inertia::render('Agenda'))->name('agenda');
            Route::get('/tabla', fn () => Inertia::render('Tabla'))->name('tabla');
            Route::get('/vestuario', fn () => Inertia::render('Vestuario'))->name('vestuario');
            Route::get('/cuota', fn () => Inertia::render('Cuota'))->name('cuota');
            Route::get('/perfil', [PerfilController::class, 'show'])->name('perfil');

            Route::post('/invitaciones', [InviteController::class, 'create'])->name('invitacion.crear');
        });
    });
});
