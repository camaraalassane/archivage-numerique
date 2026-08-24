<?php
// app/Http/Controllers/UserController.php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        // Vérifier que l'utilisateur connecté est Admin
        if (!Auth::user()->canManageUsers()) {
            abort(403, 'Vous n\'avez pas les droits pour gérer les utilisateurs.');
        }

        $users = User::all()->map(function ($user) {
            $user->has_confidential_password = !empty($user->mot_de_passe_confidentiel);
            return $user;
        });

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => [
                ['id' => User::ROLE_ARCHIVISTE, 'name' => 'Archiviste', 'color' => 'blue', 'icon' => 'mdi-folder-account'],
                ['id' => User::ROLE_GESTIONNAIRE, 'name' => 'Gestionnaire', 'color' => 'green', 'icon' => 'mdi-account-cog'],
                ['id' => User::ROLE_ADMIN, 'name' => 'Administrateur', 'color' => 'red', 'icon' => 'mdi-shield-account'],
                ['id' => User::ROLE_DIVISION, 'name' => 'Division', 'color' => 'orange', 'icon' => 'mdi-account-eye'],
            ],
            'permissions' => [
                'can_manage_users' => Auth::user()->canManageUsers(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        // Vérifier que l'utilisateur connecté est Admin
        if (!Auth::user()->canManageUsers()) {
            abort(403, 'Vous n\'avez pas les droits pour créer des utilisateurs.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:' . User::ROLE_ARCHIVISTE . ',' . User::ROLE_GESTIONNAIRE . ',' . User::ROLE_ADMIN . ',' . User::ROLE_DIVISION,
            'password' => 'required|string|min:8',
            'peut_archiver_confidentiel' => 'nullable|boolean',
            'peut_valider_confidentiel' => 'nullable|boolean',
            'peut_consulter_confidentiel' => 'nullable|boolean',
            'mot_de_passe_confidentiel' => 'nullable|string',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'password' => Hash::make($request->password),
            'peut_archiver_confidentiel' => $request->peut_archiver_confidentiel ?? false,
            'peut_valider_confidentiel' => $request->peut_valider_confidentiel ?? false,
            'peut_consulter_confidentiel' => $request->peut_consulter_confidentiel ?? false,
            'mot_de_passe_confidentiel' => $request->mot_de_passe_confidentiel ? Hash::make($request->mot_de_passe_confidentiel) : null,
        ]);

        ActivityLog::log('user_created', "A créé l'utilisateur {$user->name} ({$user->email})");

        return redirect()->back()->with('success', 'Utilisateur créé avec succès.');
    }

    public function update(Request $request, User $user)
    {
        // Vérifier que l'utilisateur connecté est Admin
        if (!Auth::user()->canManageUsers()) {
            abort(403, 'Vous n\'avez pas les droits pour modifier les utilisateurs.');
        }

        $request->validate([
            'role' => 'required|in:' . User::ROLE_ARCHIVISTE . ',' . User::ROLE_GESTIONNAIRE . ',' . User::ROLE_ADMIN . ',' . User::ROLE_DIVISION,
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'peut_archiver_confidentiel' => 'nullable|boolean',
            'peut_valider_confidentiel' => 'nullable|boolean',
            'peut_consulter_confidentiel' => 'nullable|boolean',
            'mot_de_passe_confidentiel' => 'nullable|string',
            'remove_confidential_password' => 'nullable|boolean',
        ]);

        $data = $request->only(['name', 'email', 'role']);
        $data['peut_archiver_confidentiel'] = $request->peut_archiver_confidentiel ? 1 : 0;
        $data['peut_valider_confidentiel'] = $request->peut_valider_confidentiel ? 1 : 0;
        $data['peut_consulter_confidentiel'] = $request->peut_consulter_confidentiel ? 1 : 0;

        // Cas 1 : L'admin veut supprimer le mot de passe confidentiel
        if ($request->boolean('remove_confidential_password')) {
            $data['mot_de_passe_confidentiel'] = null;
        }
        // Cas 2 : Un nouveau mot de passe est saisi → on le remplace
        elseif ($request->filled('mot_de_passe_confidentiel')) {
            $data['mot_de_passe_confidentiel'] = \Illuminate\Support\Facades\Hash::make($request->mot_de_passe_confidentiel);
        }
        // Cas 3 : Champ vide et pas de suppression → on conserve l'ancien (ne rien faire)

        $user->update($data);

        ActivityLog::log('user_updated', "A modifié l'utilisateur {$user->name} ({$user->email})");

        return redirect()->back()->with('success', 'Utilisateur mis à jour avec succès.');
    }

    public function destroy(User $user)
    {
        // Vérifier que l'utilisateur connecté est Admin
        if (!Auth::user()->canManageUsers()) {
            abort(403, 'Vous n\'avez pas les droits pour supprimer des utilisateurs.');
        }

        if ($user->id === Auth::id()) {
            return redirect()->back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $name = $user->name;
        $user->delete();
        ActivityLog::log('user_deleted', "A supprimé l'utilisateur {$name}");

        return redirect()->back()->with('success', 'Utilisateur supprimé avec succès.');
    }
}
