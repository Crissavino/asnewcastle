<?php

namespace App\Http\Middleware;

use App\Support\CurrentClub;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El member que todavía no hizo el alta (wizard de 5 pasos) no entra
 * a la app: primero completa la ficha.
 */
class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = app(CurrentClub::class)->member();

        if ($member && ! $member->profileComplete()) {
            return redirect()->route('alta');
        }

        return $next($request);
    }
}
