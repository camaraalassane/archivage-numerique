<?php
// app/Http/Controllers/DossierAnneeController.php

namespace App\Http\Controllers;

use App\Models\DossierAnnee;
use App\Models\DossierMois;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DossierAnneeController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Vérifier les permissions
        if (!$user->canManageYears()) {
            abort(403, 'Vous n\'avez pas les droits pour gérer les années.');
        }

        $annees = DossierAnnee::withCount(['mois' => function($query) {
            $query->where('active', true);
        }])->latest('annee')->get();

        // Statut calculé explicitement côté serveur
        $annees->transform(function ($a) {
            if ($a->cloturee == 1) {
                $a->statut = 'cloturee';
            } elseif ($a->active == 1) {
                $a->statut = 'active';
            } else {
                $a->statut = 'inactive';
            }
            $a->peut_cloturer = $a->cloturee == 0;
            $a->peut_rouvrir = $a->cloturee == 1;
            $a->est_active_visible = $a->active == 1;
            return $a;
        });

        return Inertia::render('Annees/Index', [
            'annees' => $annees,
            'permissions' => [
                'can_manage_years' => $user->canManageYears(),
                'can_manage_months' => $user->canManageMonths(),
                'can_manage_dossiers' => $user->canManageDossiers(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if (!$user->canManageYears()) {
            abort(403, 'Vous n\'avez pas les droits pour créer des années.');
        }

        $validated = $request->validate([
            'annee' => 'required|integer|unique:dossier_annees|min:2000|max:2100',
            'code' => 'required|unique:dossier_annees|max:50',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $existing = DossierAnnee::where('annee', $validated['annee'])->first();
        if ($existing) {
            return redirect()->back()->with('error', 'Cette année existe déjà !');
        }

        $validated['cloturee'] = false;

        $annee = DossierAnnee::create($validated);
        $this->createMoisForAnnee($annee);

        return redirect()->back()->with('success', 'Année ' . $annee->annee . ' créée avec succès avec ses 12 mois !');
    }

    public function update(Request $request, DossierAnnee $annee)
    {
        $user = Auth::user();

        if (!$user->canManageYears()) {
            abort(403, 'Vous n\'avez pas les droits pour modifier des années.');
        }

        if ($annee->cloturee) {
            return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
        }

        $validated = $request->validate([
            'annee' => 'required|integer|unique:dossier_annees,annee,' . $annee->id,
            'code' => 'required|unique:dossier_annees,code,' . $annee->id,
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $existing = DossierAnnee::where('annee', $validated['annee'])
            ->where('id', '!=', $annee->id)
            ->first();
        if ($existing) {
            return redirect()->back()->with('error', 'Une autre année avec ce numéro existe déjà !');
        }

        $annee->update($validated);

        return redirect()->back()->with('success', 'Année ' . $annee->annee . ' mise à jour !');
    }

    public function destroy(DossierAnnee $annee)
    {
        $user = Auth::user();

        if (!$user->canManageYears()) {
            abort(403, 'Vous n\'avez pas les droits pour supprimer des années.');
        }

        if ($annee->cloturee) {
            return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
        }

        if ($annee->mois()->exists()) {
            return redirect()->back()->with('error', 'Impossible : cette année contient des mois.');
        }

        $annee->delete();
        return redirect()->back()->with('success', 'Année supprimée avec succès.');
    }

    public function cloturer(DossierAnnee $annee)
    {
        $user = Auth::user();

        if (!$user->canManageYears()) {
            abort(403, 'Vous n\'avez pas les droits pour clôturer des années.');
        }

        if ($annee->cloturee) {
            return redirect()->back()->with('error', 'Cette année est déjà clôturée.');
        }

        $annee->mois()->update(['active' => false]);
        $annee->update(['cloturee' => true]);

        return redirect()->back()->with('success', 'Année ' . $annee->annee . ' clôturée avec succès !');
    }

    public function rouvrir(DossierAnnee $annee)
    {
        $user = Auth::user();

        if (!$user->canManageYears()) {
            abort(403, 'Vous n\'avez pas les droits pour rouvrir des années.');
        }

        if (!$annee->cloturee) {
            return redirect()->back()->with('error', 'Cette année n\'est pas clôturée.');
        }

        $annee->mois()->update(['active' => true]);
        $annee->update(['cloturee' => false]);

        return redirect()->back()->with('success', 'Année ' . $annee->annee . ' réouverte avec succès !');
    }

    public function toggle(DossierAnnee $annee)
    {
        $user = Auth::user();

        if (!$user->canManageYears()) {
            abort(403, 'Vous n\'avez pas les droits pour modifier la visibilité des années.');
        }

        $annee->update([
            'active' => !$annee->active
        ]);

        return redirect()->back()->with('success', 'Visibilité mise à jour');
    }

    private function createMoisForAnnee(DossierAnnee $annee)
    {
        $moisNames = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];

        foreach ($moisNames as $mois => $nom) {
            DossierMois::create([
                'annee_id' => $annee->id,
                'mois' => $mois,
                'nom_mois' => $nom,
                'code' => 'MOIS_' . $annee->annee . '_' . str_pad($mois, 2, '0', STR_PAD_LEFT),
                'description' => 'Mois de ' . $nom . ' ' . $annee->annee,
                'active' => true,
            ]);
        }
    }
}
