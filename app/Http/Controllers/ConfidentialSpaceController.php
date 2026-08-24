<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use App\Models\Archive;

class ConfidentialSpaceController extends Controller
{
    /**
     * Affiche l'arborescence de l'espace confidentiel
     */
    public function index()
    {
        // Vérifier si l'utilisateur a déverrouillé l'espace
        if (!session('unlocked_confidential')) {
            return redirect()->route('dashboard')->with('error', 'Vous devez déverrouiller l\'espace confidentiel.');
        }

        $user = \Illuminate\Support\Facades\Auth::user();
        
        $isArchiviste = $user->isArchiviste();
        $isDivision = $user->isDivision();
        $isGestionnaire = $user->isGestionnaire();
        $isAdmin = $user->isAdmin();

        // === ARBORESCENCE ===
        $treeData = \App\Models\DossierAnnee::with([
            'mois' => function ($query) {
                $query->orderBy('mois')->where('active', true);
            },
            'mois.dossiers' => function ($query) {
                $query->orderBy('ordre')
                    ->where('active', true)
                    ->withCount(['archives' => function($q) {
                        $q->where('type_document_confidentiel', 1);
                    }]);
            },
        ])
            ->where('active', true)
            ->where('cloturee', false)
            ->orderBy('annee', 'desc')
            ->get();

        // Archives récentes (limitées)
        $recentArchives = Archive::with([
            'dossier:id,nom,mois_id',
            'dossier.mois:id,nom_mois,annee_id',
            'dossier.mois.annee:id,annee',
            'createur:id,name',
        ])
            ->where('type_document_confidentiel', 1)
            ->when($isArchiviste, fn($q) => $q->where('created_by', $user->id))
            ->when($isDivision, fn($q) => $q->where('validation_status', Archive::STATUS_VALIDATED))
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn($archive) => [
                'id' => $archive->id,
                'titre' => $archive->titre,
                'reference' => $archive->reference,
                'date_document' => $archive->date_document,
                'type_document' => $archive->type_document,
                'validation_status' => $archive->validation_status,
                'created_by' => $archive->created_by,
                'createur' => $archive->createur?->name ?? 'Inconnu',
                'dossier_id' => $archive->dossier_id,
                'dossier_nom' => $archive->dossier?->nom ?? 'Non classé',
                'chemin' => $archive->dossier?->mois?->annee
                    ? $archive->dossier->mois->annee->annee . ' / ' . $archive->dossier->mois->nom_mois . ' / ' . $archive->dossier->nom
                    : 'Non classé',
                'can_modifier' => $isAdmin || $isGestionnaire || ($isArchiviste && $archive->created_by === $user->id),
            ]);

        return Inertia::render('Dashboard', [
            'treeData' => $treeData,
            'stats' => [
                'recent_archives' => $recentArchives,
            ],
            'user' => $user,
            'permissions' => [
                'can_validate' => $user->canValidateArchives(),
                'can_manage_all' => $user->canManageAll(),
                'can_manage_users' => $user->canManageUsers(),
                'can_export' => $user->canExport(),
                'can_manage_dossiers' => $user->canManageDossiers(),
                'can_view_all' => $user->canViewAllArchives(),
                'is_archiviste' => $isArchiviste,
                'is_division' => $isDivision,
                'is_gestionnaire' => $isGestionnaire,
                'is_admin' => $isAdmin,
                'can_modify_archives' => $isArchiviste || $isGestionnaire || $isAdmin,
                'can_archive_confidential' => $user->canArchiveConfidential(),
            ],
            'is_confidential_space' => true // Permet au Vue de savoir qu'on est dans l'espace
        ]);
    }

    public function getDossierArchives(Request $request, \App\Models\Dossier $dossier)
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        $query = Archive::with([
            'dossier:id,nom,mois_id',
            'dossier.mois:id,nom_mois,annee_id',
            'dossier.mois.annee:id,annee',
            'createur:id,name',
        ])
        ->where('dossier_id', $dossier->id)
        ->where('type_document_confidentiel', 1); // Seulement confidentiel

        // Filtrer selon le rôle
        if ($user->isArchiviste()) {
            $query->where('created_by', $user->id);
        } elseif ($user->isDivision()) {
            $query->where('validation_status', Archive::STATUS_VALIDATED);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('titre', 'LIKE', "%{$search}%")
                  ->orWhere('reference', 'LIKE', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('validation_status', $request->status);
        }

        $perPage = $request->per_page ?? 20;
        $archives = $query->latest('date_document')->paginate($perPage);

        $archives->getCollection()->transform(function($archive) use ($user) {
            return [
                'id' => $archive->id,
                'titre' => $archive->titre,
                'reference' => $archive->reference,
                'date_document' => $archive->date_document,
                'type_document' => $archive->type_document,
                'validation_status' => $archive->validation_status,
                'created_by' => $archive->created_by,
                'createur' => $archive->createur?->name ?? 'Inconnu',
                'dossier_id' => $archive->dossier_id,
                'dossier_nom' => $archive->dossier?->nom ?? 'Non classé',
                'chemin' => $archive->dossier?->mois?->annee
                    ? $archive->dossier->mois->annee->annee . ' / ' . $archive->dossier->mois->nom_mois . ' / ' . $archive->dossier->nom
                    : 'Non classé',
                'can_modifier' => $user->isAdmin() || $user->isGestionnaire() ||
                    ($user->isArchiviste() && $archive->created_by === $user->id),
            ];
        });

        return response()->json($archives);
    }

    /**
     * Déverrouille l'espace confidentiel
     */
    public function unlock(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        // Vérifie que l'utilisateur a accès (mot de passe défini, peut archiver confidentiel, ou admin)
        $hasAccess = $user->hasConfidentialPasswordSetup() || $user->canArchiveConfidential() || $user->isAdmin();
        if (!$hasAccess) {
            return back()->with('error', 'Vous n\'avez pas accès à l\'espace confidentiel. Veuillez contacter un administrateur.');
        }

        // Si l'utilisateur a un mot de passe confidentiel, on le vérifie
        if ($user->hasConfidentialPasswordSetup()) {
            if (Hash::check($request->password, $user->mot_de_passe_confidentiel)) {
                session(['unlocked_confidential' => true]);
                return redirect()->route('confidential.index')->with('success', 'Espace confidentiel déverrouillé.');
            }
            return back()->with('error', 'Mot de passe incorrect.');
        }

        // Admin ou utilisateur autorisé sans mot de passe spécifique : vérifier son mot de passe principal
        if (\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            session(['unlocked_confidential' => true]);
            return redirect()->route('confidential.index')->with('success', 'Espace confidentiel déverrouillé.');
        }

        return back()->with('error', 'Mot de passe incorrect.');
    }

    /**
     * Verrouille l'espace confidentiel
     */
    public function lock()
    {
        session()->forget('unlocked_confidential');
        return redirect()->route('dashboard')->with('success', 'Espace confidentiel verrouillé.');
    }
}
