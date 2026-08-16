<?php

namespace App\Http\Controllers;

use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ViewModeController extends Controller
{
    /**
     * Alterna la vista del dueño entre admin y jugador. Solo cambia lo que se
     * muestra (rol efectivo); los permisos reales del manager quedan intactos.
     */
    public function toggle(Request $request, CurrentClub $current): RedirectResponse
    {
        abort_unless($current->isOwner(), 403);

        $request->session()->put('view_as_player', ! $request->session()->get('view_as_player', false));

        return back();
    }
}
