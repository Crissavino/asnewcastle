<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    /**
     * Borra la cuenta del usuario y todos sus datos (requisito de Google Play).
     * El borrado de `users` cascada por FK a: members → attendances, dues +
     * payments, votos de figura, calificaciones, notificaciones, device_tokens;
     * los mensajes del vestuario se anonimizan (member_id → null) y los eventos/
     * gastos del club conservan el registro sin la atribución del autor.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('entrar')->with('status', __('account.deleted'));
    }
}
