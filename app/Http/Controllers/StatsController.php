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

class StatsController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if (!$user) return redirect()->route('login');

        if ($user->isDivision()) {
            abort(403, 'Vous n\'avez pas les droits pour accéder aux statistiques.');
        }

        $isAdmin = $user->isAdmin();
        $isGestionnaire = $user->isGestionnaire();
        $isArchiviste = $user->isArchiviste();

        try {
            // Totaux globaux
            $totalArchives = Archive::count();
            $totalDossiers = Dossier::count();
            $totalAnnees = DossierAnnee::count();
            $totalMois = DossierMois::count();

            // Archives par année (une requête)
            $archivesParAnnee = Archive::selectRaw('YEAR(date_document) as annee, COUNT(*) as total')
                ->whereNotNull('date_document')
                ->groupBy('annee')
                ->orderBy('annee', 'desc')
                ->get();

            // Archives par type (une requête)
            $archivesParType = Archive::selectRaw('type_document, COUNT(*) as total')
                ->whereNotNull('type_document')
                ->groupBy('type_document')
                ->get();

            // Statuts (une requête GROUP BY au lieu de 3 COUNT)
            $statutCounts = Archive::selectRaw('validation_status, count(*) as total')
                ->groupBy('validation_status')
                ->pluck('total', 'validation_status');

            $archivesParStatut = [
                'pending' => $statutCounts[Archive::STATUS_PENDING] ?? 0,
                'validated' => $statutCounts[Archive::STATUS_VALIDATED] ?? 0,
                'rejected' => $statutCounts[Archive::STATUS_REJECTED] ?? 0,
            ];

            $recentArchives = $this->getRecentArchives($user);

            // Stats personnelles archiviste
            $myStats = null;
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
                ])->where('created_by', $user->id)->first();

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
                ])->first();

                $archivistesActifs = Archive::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->distinct('created_by')
                    ->count('created_by');

                // Top 5 archivistes du mois
                $topArchivistes = Archive::selectRaw('created_by, count(*) as total')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
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

    private function getRecentArchives(User $user): array
    {
        return Archive::with([
            'dossier:id,nom,mois_id',
            'dossier.mois:id,nom_mois,annee_id',
            'dossier.mois.annee:id,annee',
            'createur:id,name',
        ])
            ->when($user->isArchiviste(), fn($q) => $q->where('created_by', $user->id))
            ->when($user->isDivision(), fn($q) =>
                $q->where('validation_status', Archive::STATUS_VALIDATED))
            ->latest()
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
