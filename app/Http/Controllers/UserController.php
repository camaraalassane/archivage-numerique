<?php
// app/Http/Controllers/UserController.php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function index()
    {
        // Vérifier que l'utilisateur connecté est Admin
        if (!Auth::user()->canManageUsers()) {
            abort(403, 'Vous n\'avez pas les droits pour gérer les utilisateurs.');
        }

        $users = User::all();

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
        ]);

        $user->update($request->only(['name', 'email', 'role']));

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

        $user->delete();
        return redirect()->back()->with('success', 'Utilisateur supprimé avec succès.');
    }
}
