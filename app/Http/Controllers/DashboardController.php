<?php
// app/Http/Controllers/DashboardController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Dossier;
use App\Models\DossierAnnee;
use App\Models\DossierMois;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $isArchiviste = $user->isArchiviste();
        $isDivision = $user->isDivision();
        $isGestionnaire = $user->isGestionnaire();
        $isAdmin = $user->isAdmin();

        // === ARBORESCENCE SANS ARCHIVES (rapide) ===
        $treeData = DossierAnnee::with([
            'mois' => function ($query) {
                $query->orderBy('mois')->where('active', true);
            },
            'mois.dossiers' => function ($query) {
                $query->orderBy('ordre')
                    ->where('active', true)
                    ->withCount('archives'); // juste le compteur
            },
        ])
            ->where('active', true)
            ->where('cloturee', false)
            ->orderBy('annee', 'desc')
            ->get();

        // === STATISTIQUES ===
        $baseQuery = Archive::query();
        if ($isArchiviste) {
            $baseQuery->where('created_by', $user->id);
        } elseif ($isDivision) {
            $baseQuery->where('validation_status', Archive::STATUS_VALIDATED);
        }

        $totalArchives = $baseQuery->count();

        $archivesParType = Archive::selectRaw('type_document, count(*) as total')
            ->when($isArchiviste, fn($q) => $q->where('created_by', $user->id))
            ->when($isDivision, fn($q) => $q->where('validation_status', Archive::STATUS_VALIDATED))
            ->groupBy('type_document')
            ->pluck('total', 'type_document');

        $statutCounts = Archive::selectRaw('validation_status, count(*) as total')
            ->when($isArchiviste, fn($q) => $q->where('created_by', $user->id))
            ->groupBy('validation_status')
            ->pluck('total', 'validation_status');

        $archivesParStatut = [
            'pending' => $statutCounts[Archive::STATUS_PENDING] ?? 0,
            'validated' => $statutCounts[Archive::STATUS_VALIDATED] ?? 0,
            'rejected' => $statutCounts[Archive::STATUS_REJECTED] ?? 0,
        ];

        // Archives récentes (limitées)
        $recentArchives = Archive::with([
            'dossier:id,nom,mois_id',
            'dossier.mois:id,nom_mois,annee_id',
            'dossier.mois.annee:id,annee',
            'createur:id,name',
        ])
            ->when($isArchiviste, fn($q) => $q->where('created_by', $user->id))
            ->when($isDivision, fn($q) => $q->where('validation_status', Archive::STATUS_VALIDATED))
            ->latest()
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

        // === STATS PERSONNELLES ===
        $myStats = null;

        if ($isArchiviste) {
            $persoStats = Archive::selectRaw('
                    count(*) as total,
                    sum(case when validation_status = ? then 1 else 0 end) as en_attente,
                    sum(case when validation_status = ? then 1 else 0 end) as validees,
                    sum(case when month(created_at) = ? and year(created_at) = ? then 1 else 0 end) as ce_mois,
                    sum(case when created_at between ? and ? then 1 else 0 end) as cette_semaine
                ', [
                Archive::STATUS_PENDING,
                Archive::STATUS_VALIDATED,
                now()->month,
                now()->year,
                now()->startOfWeek(),
                now()->endOfWeek(),
            ])
                ->where('created_by', $user->id)
                ->first();

            $myStats = [
                'total_archives' => $persoStats->total ?? 0,
                'archives_ce_mois' => $persoStats->ce_mois ?? 0,
                'archives_cette_semaine' => $persoStats->cette_semaine ?? 0,
                'en_attente' => $persoStats->en_attente ?? 0,
                'validees' => $persoStats->validees ?? 0,
            ];
        } elseif ($isGestionnaire || $isAdmin) {
            $persoStats = Archive::selectRaw('
                    count(*) as total,
                    sum(case when validation_status = ? then 1 else 0 end) as en_attente,
                    sum(case when validation_status = ? then 1 else 0 end) as validees,
                    sum(case when validation_status = ? then 1 else 0 end) as rejetees,
                    sum(case when month(created_at) = ? and year(created_at) = ? then 1 else 0 end) as ce_mois
                ', [
                Archive::STATUS_PENDING,
                Archive::STATUS_VALIDATED,
                Archive::STATUS_REJECTED,
                now()->month,
                now()->year,
            ])->first();

            $archivistesActifs = Archive::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->distinct('created_by')
                ->count('created_by');

            $myStats = [
                'total_archives' => $persoStats->total ?? 0,
                'archives_ce_mois' => $persoStats->ce_mois ?? 0,
                'en_attente' => $persoStats->en_attente ?? 0,
                'validees' => $persoStats->validees ?? 0,
                'rejetees' => $persoStats->rejetees ?? 0,
                'archivistes_actifs_ce_mois' => $archivistesActifs,
            ];
        }

        return Inertia::render('Dashboard', [
            'treeData' => $treeData,
            'stats' => [
                'total_archives' => $totalArchives,
                'total_annees' => DossierAnnee::where('cloturee', false)->count(),
                'total_mois' => DossierMois::where('active', true)->count(),
                'total_dossiers' => Dossier::where('active', true)->count(),
                'recent_archives' => $recentArchives,
                'archives_par_type' => $archivesParType,
                'archives_par_statut' => $archivesParStatut,
                'my_stats' => $myStats,
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
            ]
        ]);
    }

    /**
     * Charge les archives d'un dossier spécifique (pour le Dashboard)
     */
    public function getDossierArchives(Request $request, Dossier $dossier)
    {
        $user = Auth::user();

        $query = Archive::with([
            'dossier:id,nom,mois_id',
            'dossier.mois:id,nom_mois,annee_id',
            'dossier.mois.annee:id,annee',
            'createur:id,name',
        ])
        ->where('dossier_id', $dossier->id);

        // Filtrer selon le rôle
        if ($user->isArchiviste()) {
            $query->where('created_by', $user->id);
        } elseif ($user->isDivision()) {
            $query->where('validation_status', Archive::STATUS_VALIDATED);
        }

        // Filtres optionnels
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

        // Pagination
        $perPage = $request->per_page ?? 20;
        $archives = $query->latest('date_document')->paginate($perPage);

        // Formater les données
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

    public function search(Request $request)
    {
        $user = Auth::user();
        $search = $request->input('search');

        $results = Archive::with([
            'dossier:id,nom,mois_id',
            'dossier.mois:id,nom_mois,annee_id',
            'dossier.mois.annee:id,annee',
            'createur:id,name',
        ])
            ->when($user->isArchiviste(), fn($q) => $q->where('created_by', $user->id))
            ->when($user->isDivision(), fn($q) => $q->where('validation_status', Archive::STATUS_VALIDATED))
            ->where(function ($q) use ($search) {
                $q->where('titre', 'LIKE', "%{$search}%")
                    ->orWhere('reference', 'LIKE', "%{$search}%")
                    ->orWhere('mots_cles', 'LIKE', "%{$search}%");
            })
            ->limit(10)
            ->get()
            ->map(fn($archive) => [
                'id' => $archive->id,
                'titre' => $archive->titre,
                'reference' => $archive->reference,
                'chemin' => $archive->dossier?->mois?->annee
                    ? $archive->dossier->mois->annee->annee . ' / ' . $archive->dossier->mois->nom_mois . ' / ' . $archive->dossier->nom
                    : 'Non classé',
                'date_document' => $archive->date_document,
                'type_document' => $archive->type_document,
                'validation_status' => $archive->validation_status,
                'createur' => $archive->createur?->name ?? 'Inconnu',
            ]);

        return response()->json($results);
    }
}
