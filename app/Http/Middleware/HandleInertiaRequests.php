<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                // On s'assure que si l'user est null, on renvoie null proprement
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role, // AJOUT DU RÔLE
                    'role_name' => $request->user()->role_name, // AJOUT DU NOM DU RÔLE
                ] : null,
            ],
            // Ajout du nom de l'app pour ton GuestLayout
            'appName' => config('app.name'),
            // Ajout des messages flash pour tes alertes Vuetify
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
