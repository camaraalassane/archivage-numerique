<?php
// app/Http/Controllers/StatsController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Dossier;
use App\Models\DossierAnnee;
use App\Models\DossierMois;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user) return redirect()->route('login');

        $isAdmin = $user->isAdmin();
        $isGestionnaire = $user->isGestionnaire();
        $isArchiviste = $user->isArchiviste();

        $isConfidential = $request->query('space') === 'confidential';

        if ($isConfidential && !$user->canViewConfidential()) {
            abort(403, 'Accès refusé aux statistiques confidentielles.');
        }

        $requiresUnlock = $isConfidential && !session('unlocked_confidential');

        try {
            // Définir $condition ICI (hors du cache) pour qu'elle soit accessible
            // aux requêtes de stats personnelles archiviste/gestionnaire/admin
            $condition = function($q) use ($isConfidential) {
                if ($isConfidential) {
                    $q->where('type_document_confidentiel', 1);
                } else {
                    $q->where('type_document_confidentiel', '!=', 1)
                      ->orWhereNull('type_document_confidentiel');
                }
            };

            if ($requiresUnlock) {
                // Si l'espace est verrouillé, on ne charge aucune stat
                $globalStats = [
                    'totalArchives' => 0, 'totalDossiers' => 0,
                    'totalAnnees' => 0, 'totalMois' => 0,
                    'archivesParAnnee' => [], 'archivesParType' => [],
                    'archivesParStatut' => ['pending' => 0, 'validated' => 0, 'rejected' => 0]
                ];
            } else {
                $cacheKey = $isConfidential ? 'stats_confidential_global' : 'stats_global';

                $globalStats = Cache::remember($cacheKey, 300, function () use ($isConfidential, $condition) {
                    $statutCounts = Archive::selectRaw('validation_status, count(*) as total')
                        ->where($condition)
                        ->groupBy('validation_status')
                        ->pluck('total', 'validation_status');

                    return [
                        'totalArchives' => Archive::where($condition)->count(),
                        'totalDossiers' => Dossier::whereHas('archives', $condition)->count(),
                        'totalAnnees' => DossierAnnee::whereHas('mois.dossiers.archives', $condition)->count(),
                        'totalMois' => DossierMois::whereHas('dossiers.archives', $condition)->count(),
                        'archivesParAnnee' => Archive::selectRaw('YEAR(date_document) as annee, COUNT(*) as total')
                            ->where($condition)
                            ->whereNotNull('date_document')
                            ->groupBy('annee')
                            ->orderBy('annee', 'desc')
                            ->get(),
                        'archivesParType' => Archive::selectRaw('type_document, COUNT(*) as total')
                            ->where($condition)
                            ->whereNotNull('type_document')
                            ->groupBy('type_document')
                            ->get(),
                        'archivesParStatut' => [
                            'pending' => $statutCounts[Archive::STATUS_PENDING] ?? 0,
                            'validated' => $statutCounts[Archive::STATUS_VALIDATED] ?? 0,
                            'rejected' => $statutCounts[Archive::STATUS_REJECTED] ?? 0,
                        ]
                    ];
                });
            }

            $totalArchives = $globalStats['totalArchives'];
            $totalDossiers = $globalStats['totalDossiers'];
            $totalAnnees = $globalStats['totalAnnees'];
            $totalMois = $globalStats['totalMois'];
            $archivesParAnnee = $globalStats['archivesParAnnee'];
            $archivesParType = $globalStats['archivesParType'];
            $archivesParStatut = $globalStats['archivesParStatut'];

            $recentArchives = $this->getRecentArchives($user, $isConfidential, $requiresUnlock);

            // Stats personnelles archiviste
            $myStats = null;
            if (!$requiresUnlock) {
                if ($isArchiviste) {
                    $perso = Archive::selectRaw('
                        count(*) as total,
                        sum(case when validation_status = ? then 1 else 0 end) as en_attente,
                        sum(case when validation_status = ? then 1 else 0 end) as validees,
                        sum(case when month(created_at) = ? and year(created_at) = ? then 1 else 0 end) as ce_mois,
                        sum(case when created_at between ? and ? then 1 else 0 end) as cette_semaine
                    ', [
                        Archive::STATUS_PENDING,
                        Archive::STATUS_VALIDATED,
                        now()->month, now()->year,
                        now()->startOfWeek(), now()->endOfWeek(),
                    ])
                    ->where($condition)
                    ->where('created_by', $user->id)->first();

                    $myStats = [
                        'total_archives' => $perso->total ?? 0,
                        'archives_ce_mois' => $perso->ce_mois ?? 0,
                        'archives_cette_semaine' => $perso->cette_semaine ?? 0,
                        'en_attente' => $perso->en_attente ?? 0,
                        'validees' => $perso->validees ?? 0,
                    ];
                } elseif ($isGestionnaire || $isAdmin) {
                    $perso = Archive::selectRaw('
                        count(*) as total,
                        sum(case when validation_status = ? then 1 else 0 end) as en_attente,
                        sum(case when validation_status = ? then 1 else 0 end) as validees,
                        sum(case when validation_status = ? then 1 else 0 end) as rejetees,
                        sum(case when month(created_at) = ? and year(created_at) = ? then 1 else 0 end) as ce_mois,
                        sum(case when created_at between ? and ? then 1 else 0 end) as cette_semaine
                    ', [
                        Archive::STATUS_PENDING,
                        Archive::STATUS_VALIDATED,
                        Archive::STATUS_REJECTED,
                        now()->month, now()->year,
                        now()->startOfWeek(), now()->endOfWeek(),
                    ])
                    ->where($condition)
                    ->first();

                    $archivistesActifs = Archive::whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year)
                        ->where($condition)
                        ->distinct('created_by')
                        ->count('created_by');

                    // Top 5 archivistes du mois
                    $topArchivistes = Archive::selectRaw('created_by, count(*) as total')
                        ->whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year)
                        ->where($condition)
                        ->groupBy('created_by')
                        ->orderByDesc('total')
                        ->limit(5)
                        ->with('createur:id,name')
                        ->get()
                        ->map(fn($a) => [
                            'nom' => $a->createur?->name ?? 'Inconnu',
                            'total' => $a->total,
                        ]);

                    $myStats = [
                        'total_archives' => $perso->total ?? 0,
                        'archives_ce_mois' => $perso->ce_mois ?? 0,
                        'archives_cette_semaine' => $perso->cette_semaine ?? 0,
                        'en_attente' => $perso->en_attente ?? 0,
                        'validees' => $perso->validees ?? 0,
                        'rejetees' => $perso->rejetees ?? 0,
                        'archivistes_actifs_ce_mois' => $archivistesActifs,
                        'top_archivistes' => $topArchivistes,
                    ];
                }
            }

            // Stats de validation (gestionnaire/admin uniquement)
            $validationStats = null;
            if ($isGestionnaire || $isAdmin) {
                $validationStats = [
                    'en_attente' => $archivesParStatut['pending'],
                    'validees' => $archivesParStatut['validated'],
                    'rejetees' => $archivesParStatut['rejected'],
                    'taux_validation' => $totalArchives > 0
                        ? round(($archivesParStatut['validated'] / $totalArchives) * 100, 1)
                        : 0,
                ];
            }

            return Inertia::render('Stats/Index', [
                'stats' => [
                    'total_archives' => $totalArchives,
                    'total_dossiers' => $totalDossiers,
                    'total_annees' => $totalAnnees,
                    'total_mois' => $totalMois,
                    'archives_par_annee' => $archivesParAnnee,
                    'archives_par_type' => $archivesParType,
                    'archives_par_statut' => $archivesParStatut,
                    'recent_archives' => $recentArchives,
                    'my_stats' => $myStats,
                    'validation_stats' => $validationStats,
                    'user_role' => $user->role,
                    'is_archiviste' => $isArchiviste,
                    'is_gestionnaire' => $isGestionnaire,
                    'is_admin' => $isAdmin,
                    'is_division' => $user->isDivision(),
                    'has_confidential_access' => $user->canViewConfidential(),
                    'is_confidential_space' => $isConfidential,
                    'requires_unlock' => $requiresUnlock,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur StatsController: ' . $e->getMessage());
            return Inertia::render('Stats/Index', [
                'stats' => [
                    'total_archives' => 0, 'total_dossiers' => 0,
                    'total_annees' => 0, 'total_mois' => 0,
                    'archives_par_annee' => [], 'archives_par_type' => [],
                    'archives_par_statut' => [], 'recent_archives' => [],
                    'my_stats' => null, 'validation_stats' => null,
                    'error' => 'Erreur lors du chargement : ' . $e->getMessage()
                ]
            ]);
        }
    }

    private function getRecentArchives(User $user, bool $isConfidential = false, bool $requiresUnlock = false): array
    {
        if ($requiresUnlock) return [];

        return Archive::with([
            'dossier:id,nom,mois_id',
            'dossier.mois:id,nom_mois,annee_id',
            'dossier.mois.annee:id,annee',
            'createur:id,name',
        ])
            ->where(function($q) use ($isConfidential) {
                if ($isConfidential) {
                    $q->where('type_document_confidentiel', 1);
                } else {
                    $q->where('type_document_confidentiel', '!=', 1)
                      ->orWhereNull('type_document_confidentiel');
                }
            })
            ->when($user->isArchiviste(), fn($q) => $q->where('created_by', $user->id))
            ->when($user->isDivision(), fn($q) =>
                $q->where('validation_status', Archive::STATUS_VALIDATED))
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn($archive) => [
                'id' => $archive->id,
                'titre' => $archive->titre ?? 'Sans titre',
                'reference' => $archive->reference ?? 'Sans référence',
                'date_document' => $archive->date_document,
                'type_document' => $archive->type_document ?? 'inconnu',
                'chemin' => $archive->dossier?->mois?->annee
                    ? $archive->dossier->mois->annee->annee . ' / ' . $archive->dossier->mois->nom_mois . ' / ' . $archive->dossier->nom
                    : 'Non classé',
                'dossier_id' => $archive->dossier_id,
                'dossier_nom' => $archive->dossier?->nom ?? 'Non classé',
                'createur' => $archive->createur?->name ?? 'Inconnu',
                'validation_status' => $archive->validation_status ?? 'pending',
                'can_modifier' => $user->isAdmin() || $user->isGestionnaire()
                    || ($user->isArchiviste() && $archive->created_by === $user->id),
            ])
            ->toArray();
    }
}
