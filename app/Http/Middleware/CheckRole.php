<?php
// app/Http/Middleware/CheckRole.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int  ...$roles  // Les rôles autorisés (ex: 1 pour Archiviste, 2 pour Gestionnaire)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // Vérifier si l'utilisateur est connecté
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Si aucun rôle n'est spécifié, on autorise
        if (empty($roles)) {
            return $next($request);
        }

        // Vérifier si l'utilisateur a l'un des rôles autorisés
        foreach ($roles as $role) {
            if ((int)$user->role === (int)$role) {
                return $next($request);
            }
        }

        // Si l'utilisateur n'a pas le bon rôle
        abort(403, 'Vous n\'avez pas les droits nécessaires pour accéder à cette page.');
    }
}
