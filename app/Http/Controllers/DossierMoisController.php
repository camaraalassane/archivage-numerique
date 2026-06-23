<?php
// app/Http/Controllers/DossierMoisController.php

namespace App\Http\Controllers;

use App\Models\DossierMois;
use App\Models\DossierAnnee;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DossierMoisController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Vérifier les permissions
        if (!$user->canManageMonths()) {
            abort(403, 'Vous n\'avez pas les droits pour gérer les mois.');
        }

        return Inertia::render('Mois/Index', [
            'mois' => DossierMois::with('annee')->latest()->get(),
            'annees' => DossierAnnee::where('active', true)->orderBy('annee', 'desc')->get(['id', 'annee']),
            'permissions' => [
                'can_manage_months' => $user->canManageMonths(),
                'can_manage_years' => $user->canManageYears(),
                'can_manage_dossiers' => $user->canManageDossiers(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageMonths()) {
                abort(403, 'Vous n\'avez pas les droits pour créer des mois.');
            }

            $validated = $request->validate([
                'annee_id' => 'required|exists:dossier_annees,id',
                'mois' => 'required|integer|min:1|max:12',
                'nom_mois' => 'required|string|max:50',
                'code' => 'required|unique:dossier_mois|max:50',
                'description' => 'nullable|string',
                'active' => 'boolean',
            ]);

            $annee = DossierAnnee::find($validated['annee_id']);
            if ($annee && $annee->cloturee) {
                return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
            }

            DossierMois::create($validated);

            return redirect()->back()->with('success', 'Mois créé avec succès !');
        } catch (\Exception $e) {
            \Log::error('❌ Erreur store mois:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function update(Request $request, DossierMois $dossierMois)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageMonths()) {
                abort(403, 'Vous n\'avez pas les droits pour modifier des mois.');
            }

            $validated = $request->validate([
                'annee_id' => 'required|exists:dossier_annees,id',
                'mois' => 'required|integer|min:1|max:12',
                'nom_mois' => 'required|string|max:50',
                'code' => 'required|unique:dossier_mois,code,' . $dossierMois->id,
                'description' => 'nullable|string',
                'active' => 'boolean',
            ]);

            $annee = DossierAnnee::find($validated['annee_id']);
            if ($annee && $annee->cloturee) {
                return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
            }

            $dossierMois->update($validated);

            return redirect()->back()->with('success', 'Mois mis à jour !');
        } catch (\Exception $e) {
            \Log::error('❌ Erreur update mois:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function destroy(DossierMois $dossierMois)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageMonths()) {
                abort(403, 'Vous n\'avez pas les droits pour supprimer des mois.');
            }

            if ($dossierMois->dossiers()->exists()) {
                return redirect()->back()->with('error', 'Impossible : ce mois contient des dossiers.');
            }

            $dossierMois->delete();
            return redirect()->back()->with('success', 'Mois supprimé avec succès.');
        } catch (\Exception $e) {
            \Log::error('❌ Erreur destroy mois:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function byAnnee(int $anneeId)
    {
        $mois = DossierMois::where('annee_id', $anneeId)
            ->where('active', true)
            ->orderBy('mois')
            ->get();

        return response()->json($mois);
    }

    public function toggle(DossierMois $dossierMois)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageMonths()) {
                abort(403, 'Vous n\'avez pas les droits pour modifier le statut des mois.');
            }

            $dossierMois->active = !$dossierMois->active;
            $dossierMois->save();

            return redirect()->back()->with('success', 'Statut mis à jour');
        } catch (\Exception $e) {
            \Log::error('❌ Erreur toggle mois:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }
}
