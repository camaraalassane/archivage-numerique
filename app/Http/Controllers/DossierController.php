<?php
// app/Http/Controllers/DossierController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Dossier;
use App\Models\DossierAnnee;
use App\Models\DossierMois;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DossierController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if (!$user->canManageDossiers()) {
            abort(403, 'Vous n\'avez pas les droits pour gérer les dossiers.');
        }

        return Inertia::render('Dossiers/Index', [
            'dossiers' => Dossier::with(['mois.annee'])->latest()->get(),
            'annees' => DossierAnnee::where('active', true)->orderBy('annee', 'desc')->get(['id', 'annee']),
            'mois' => DossierMois::with('annee')->where('active', true)->get(['id', 'annee_id', 'mois', 'nom_mois']),
            'permissions' => [
                'can_manage_dossiers' => $user->canManageDossiers(),
                'can_manage_years' => $user->canManageYears(),
                'can_manage_months' => $user->canManageMonths(),
            ]
        ]);
    }

    /**
     * Charge les archives d'un dossier de façon asynchrone (appelé par le Dashboard et la page Dossiers).
     * Supporte la pagination, le filtre par statut et la recherche.
     */
    public function archives(Request $request, Dossier $dossier)
    {
        $user = Auth::user();

        $query = Archive::with([
            'createur:id,name',
            'validateur:id,name',
        ])
            ->where('dossier_id', $dossier->id);

        // Archiviste et Division : uniquement les archives validées
        if ($user->isArchiviste() || $user->isDivision()) {
            $query->where('validation_status', Archive::STATUS_VALIDATED);
        }

        // Filtre par statut
        if ($request->status && $request->status !== 'all') {
            $query->where('validation_status', $request->status);
        }

        // Recherche
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('titre', 'LIKE', "%{$search}%")
                    ->orWhere('reference', 'LIKE', "%{$search}%")
                    ->orWhere('mots_cles', 'LIKE', "%{$search}%");
            });
        }

        $perPage = $request->per_page ?? 20;
        $archives = $query->latest('date_document')->paginate($perPage);

        // Transformer pour le frontend
        $archives->getCollection()->transform(function ($archive) use ($user) {
            return [
                'id' => $archive->id,
                'titre' => $archive->titre,
                'reference' => $archive->reference,
                'date_document' => $archive->date_document,
                'type_document' => $archive->type_document,
                'fichier_taille' => $archive->fichier_taille,
                'validation_status' => $archive->validation_status,
                'created_by' => $archive->created_by,
                'createur' => $archive->createur?->name ?? 'Inconnu',
                'validateur' => $archive->validateur?->name ?? null,
                'mots_cles' => $archive->mots_cles,
                'description' => $archive->description,
                'can_modifier' => $user->isAdmin() || $user->isGestionnaire()
                    || ($user->isArchiviste() && $archive->created_by === $user->id),
                'can_telecharger' => true,
            ];
        });

        return response()->json($archives);
    }

    public function store(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageDossiers()) {
                abort(403, 'Vous n\'avez pas les droits pour créer des dossiers.');
            }

            $validated = $request->validate([
                'mois_id' => 'required|exists:dossier_mois,id',
                'nom' => 'required|string|max:255',
                'code' => 'required|unique:dossiers|max:50',
                'description' => 'nullable|string',
                'couleur' => 'nullable|string|max:20',
                'ordre' => 'integer|min:0',
                'active' => 'boolean',
            ]);

            $mois = DossierMois::with('annee')->find($validated['mois_id']);
            if ($mois && $mois->annee && $mois->annee->cloturee) {
                return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
            }

            Dossier::create($validated);

            return redirect()->back()->with('success', 'Dossier créé avec succès !');
        } catch (\Exception $e) {
            \Log::error('❌ Erreur store:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function storeMultiple(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageDossiers()) {
                abort(403, 'Vous n\'avez pas les droits pour créer des dossiers en lot.');
            }

            $validated = $request->validate([
                'mois_id' => 'required|exists:dossier_mois,id',
                'dossiers' => 'required|array|min:1',
                'dossiers.*.nom' => 'required|string|max:255',
                'dossiers.*.couleur' => 'nullable|string|max:20',
                'dossiers.*.ordre' => 'nullable|integer|min:0',
                'dossiers.*.date_creation' => 'nullable|date_format:Y-m-d H:i:s',
                'description' => 'nullable|string',
                'active' => 'boolean',
            ]);

            $moisItem = DossierMois::with('annee')->findOrFail($request->mois_id);

            if ($moisItem->annee && $moisItem->annee->cloturee) {
                return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
            }

            $anneeClean = $moisItem->annee->annee;
            $moisClean = $moisItem->mois;
            $createdCount = 0;
            $existingCount = 0;
            $errors = [];

            foreach ($request->dossiers as $index => $dossierData) {
                $nom = trim($dossierData['nom']);
                if (empty($nom)) {
                    $errors[] = "Le dossier #" . ($index + 1) . " a un nom vide";
                    continue;
                }

                $existing = Dossier::where('mois_id', $request->mois_id)
                    ->where('nom', $nom)
                    ->first();

                if ($existing) {
                    $existingCount++;
                    continue;
                }

                $nomClean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_',
                    iconv('UTF-8', 'ASCII//TRANSLIT', $nom)
                ));

                $codeUnique = "DOSSIER_{$anneeClean}_{$moisClean}_{$nomClean}";
                $codeCounter = 1;
                $finalCode = $codeUnique;
                while (Dossier::where('code', $finalCode)->exists()) {
                    $finalCode = $codeUnique . '_' . $codeCounter;
                    $codeCounter++;
                }

                $dossier = new Dossier([
                    'mois_id' => $request->mois_id,
                    'nom' => $nom,
                    'code' => $finalCode,
                    'couleur' => $dossierData['couleur'] ?? '#1976D2',
                    'ordre' => $dossierData['ordre'] ?? $index,
                    'description' => $request->description ?? null,
                    'active' => $request->active ?? true,
                ]);

                if (!empty($dossierData['date_creation'])) {
                    $dossier->created_at = $dossierData['date_creation'];
                    $dossier->updated_at = $dossierData['date_creation'];
                }

                $dossier->save();
                $createdCount++;
            }

            $message = $createdCount . ' dossier(s) créé(s) avec succès';
            if ($existingCount > 0) $message .= '. ' . $existingCount . ' dossier(s) existant(s) ignoré(s)';
            if (!empty($errors)) $message .= '. Erreurs: ' . implode(', ', $errors);

            return redirect()->back()->with(
                $createdCount > 0 ? 'success' : 'warning',
                $message
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            \Log::error('❌ Erreur storeMultiple:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur serveur: ' . $e->getMessage());
        }
    }

    public function update(Request $request, Dossier $dossier)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageDossiers()) {
                abort(403, 'Vous n\'avez pas les droits pour modifier des dossiers.');
            }

            $validated = $request->validate([
                'mois_id' => 'required|exists:dossier_mois,id',
                'nom' => 'required|string|max:255',
                'code' => 'required|unique:dossiers,code,' . $dossier->id,
                'description' => 'nullable|string',
                'couleur' => 'nullable|string|max:20',
                'ordre' => 'integer|min:0',
                'active' => 'boolean',
            ]);

            $mois = DossierMois::with('annee')->find($validated['mois_id']);
            if ($mois && $mois->annee && $mois->annee->cloturee) {
                return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
            }

            $dossier->update($validated);

            return redirect()->back()->with('success', 'Dossier mis à jour avec succès !');
        } catch (\Exception $e) {
            \Log::error('❌ Erreur update:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function destroy(Dossier $dossier)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageDossiers()) {
                abort(403, 'Vous n\'avez pas les droits pour supprimer des dossiers.');
            }

            if ($dossier->archives()->exists()) {
                return redirect()->back()->with('error', 'Impossible : ce dossier contient des documents.');
            }

            $dossier->delete();
            return redirect()->back()->with('success', 'Dossier supprimé avec succès.');
        } catch (\Exception $e) {
            \Log::error('❌ Erreur destroy:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function byMois(int $moisId)
    {
        $dossiers = Dossier::where('mois_id', $moisId)
            ->where('active', true)
            ->orderBy('ordre')
            ->get(['id', 'nom', 'code', 'couleur', 'ordre']);

        return response()->json($dossiers);
    }

    public function toggle(Dossier $dossier)
    {
        try {
            $user = Auth::user();

            if (!$user->canManageDossiers()) {
                abort(403, 'Vous n\'avez pas les droits pour modifier le statut des dossiers.');
            }

            $dossier->active = !$dossier->active;
            $dossier->save();

            return redirect()->back()->with('success', 'Statut mis à jour avec succès');
        } catch (\Exception $e) {
            \Log::error('❌ Erreur toggle:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function list(Request $request)
    {
        $query = Dossier::with(['mois.annee'])
            ->where('active', true)
            ->orderBy('nom');

        if ($request->has('search') && $request->search) {
            $query->where('nom', 'LIKE', '%' . $request->search . '%');
        }

        $dossiers = $query->get()->map(function ($dossier) {
            return [
                'id' => $dossier->id,
                'nom' => $dossier->nom,
                'code' => $dossier->code,
                'couleur' => $dossier->couleur,
                'chemin' => $dossier->mois && $dossier->mois->annee
                    ? $dossier->mois->annee->annee . ' / ' . $dossier->mois->nom_mois . ' / ' . $dossier->nom
                    : $dossier->nom,
                'mois_id' => $dossier->mois_id,
                'annee_id' => $dossier->mois ? $dossier->mois->annee_id : null,
            ];
        });

        return response()->json($dossiers);
    }
}
