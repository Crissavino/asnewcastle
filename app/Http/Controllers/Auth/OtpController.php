<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Club;
use App\Models\User;
use App\Services\Otp\OtpManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Propaganistas\LaravelPhone\Rules\Phone as PhoneRule;

class OtpController extends Controller
{
    /** Países del selector: el plantel es rumano, argentino, colombiano, italiano y español. */
    public const COUNTRIES = ['RO', 'AR', 'CO', 'IT', 'ES'];

    public function showPhone(): Response
    {
        return Inertia::render('Auth/Telefono', [
            'countries' => self::COUNTRIES,
        ]);
    }

    public function sendCode(Request $request, OtpManager $otp): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', (new PhoneRule)->country(self::COUNTRIES)],
        ]);

        $phone = phone($validated['phone'], self::COUNTRIES)->formatE164();

        if (! $otp->send($phone)) {
            throw ValidationException::withMessages([
                'phone' => __('auth.too_many_codes', [
                    'minutes' => (int) ceil($otp->secondsUntilNextSend($phone) / 60),
                ]),
            ]);
        }

        $request->session()->put('otp_phone', $phone);

        return redirect()->route('codigo');
    }

    public function showCode(Request $request): Response|RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return redirect()->route('entrar');
        }

        return Inertia::render('Auth/Codigo', [
            'phone_masked' => $this->mask($phone),
        ]);
    }

    public function resend(Request $request, OtpManager $otp): RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return redirect()->route('entrar');
        }

        if (! $otp->send($phone)) {
            throw ValidationException::withMessages([
                'code' => __('auth.too_many_codes', [
                    'minutes' => (int) ceil($otp->secondsUntilNextSend($phone) / 60),
                ]),
            ]);
        }

        return back();
    }

    public function verify(Request $request, OtpManager $otp): RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return redirect()->route('entrar');
        }

        $validated = $request->validate([
            // 6 = código real; 7 = largo del código maestro temporal
            'code' => ['required', 'digits_between:6,7'],
        ]);

        if (! $otp->verify($phone, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => __('auth.invalid_code'),
            ]);
        }

        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['locale' => $this->localeForPhone($phone)],
        );

        // La VERDAD es el idioma elegido en la app (users.locale, editable en el
        // perfil). El del teléfono es solo el default INICIAL: se aplica si el
        // usuario todavía no eligió uno de verdad, y nunca pisa una preferencia
        // ya elegida — un +40 que habla español manda. Como el club es es/ro,
        // 'en' (el fallback) cuenta como "sin elegir" y toma el default.
        $chosen = in_array($user->locale, ['es', 'ro'], true)
            ? $user->locale
            : $this->localeForPhone($phone);

        $user->forceFill([
            'phone_verified_at' => now(),
            'locale' => $chosen,
        ])->save();

        // Sesión larga: el jugador se loguea una vez y no vuelve en meses.
        Auth::login($user, remember: true);

        $request->session()->regenerate();
        $request->session()->forget('otp_phone');

        // Si llegó con un link de invitación, se lo asocia al club acá
        // (o se le levanta la baja si es un ex-member que vuelve).
        if ($clubId = $request->session()->pull('invite_club_id')) {
            InviteController::joinOrRejoin($user, $clubId);
        } elseif (! $user->members()->whereNull('left_at')->exists() && Club::count() === 1) {
            // Lanzamiento de un solo club: un usuario verificado que no está en
            // ningún club activo se suma al único club. Así el flujo de la guía
            // funciona en un solo login (bajar app → entrar), sin la vuelta por
            // el navegador. El código maestro es el candado interino; cuando haya
            // más de un club o WhatsApp OTP, esta rama deja de aplicar.
            InviteController::joinOrRejoin($user, Club::value('id'));
        }

        return redirect()->intended(route('agenda'));
    }

    public function setLocale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'in:'.implode(',', SetLocale::SUPPORTED)],
        ]);

        $request->session()->put('locale', $validated['locale']);
        $request->user()?->update(['locale' => $validated['locale']]);

        return back();
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('entrar');
    }

    /**
     * Default INICIAL de idioma según el prefijo del teléfono: rumano para +40,
     * español para el resto (AR/CO/IT/ES). Es solo la primera adivinanza: en
     * cuanto el jugador elige idioma en la app, esa preferencia es la que manda.
     */
    protected function localeForPhone(string $phone): string
    {
        return str_starts_with($phone, '+40') ? 'ro' : 'es';
    }

    /** Nunca mostramos el número completo: +40•••••678. */
    protected function mask(string $phone): string
    {
        return substr($phone, 0, 3).str_repeat('•', max(strlen($phone) - 6, 0)).substr($phone, -3);
    }
}
