<?php
// app/Http/Controllers/ArchivisteController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Dossier;
use App\Models\DossierAnnee;
use App\Models\DossierMois;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArchivisteController extends Controller
{
    /**
     * Affiche les archives en attente et rejetées pour l'Archiviste
     */
    public function pendingRejected(Request $request)
    {
        $user = Auth::user();

        // Seul l'Archiviste peut accéder à cette page
        if (!$user->isArchiviste()) {
            abort(403, 'Vous n\'avez pas les droits pour accéder à cette page.');
        }

        $query = Archive::with(['dossier.mois.annee', 'createur', 'validateur'])
            ->whereIn('validation_status', [Archive::STATUS_PENDING, Archive::STATUS_REJECTED])
            ->where('created_by', $user->id);

        // === FILTRES DE RECHERCHE ===
        $query->when($request->search, function ($q, $search) {
            $q->where(function ($sub) use ($search) {
                $sub->where('titre', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('mots_cles', 'like', "%{$search}%");
            });
        })
        ->when($request->dossier_id, fn($q, $d) => $q->where('dossier_id', $d))
        ->when($request->type, fn($q, $t) => $q->where('type_document', $t))
        ->when($request->validation_status, fn($q, $s) => $q->where('validation_status', $s))
        ->when($request->date_debut, fn($q, $dd) => $q->whereDate('date_document', '>=', $dd))
        ->when($request->date_fin, fn($q, $df) => $q->whereDate('date_document', '<=', $df));

        return Inertia::render('Archiviste/PendingRejected', [
            'filters' => $request->all(['search', 'dossier_id', 'type', 'date_debut', 'date_fin', 'validation_status']),
            'archives' => $query->latest()->paginate(15)->withQueryString(),
            'dossiers' => Dossier::with(['mois.annee'])->orderBy('nom')->get(['id', 'nom', 'mois_id', 'couleur']),
            'type_documents' => Archive::select('type_document')->distinct()->pluck('type_document'),
            'annees' => DossierAnnee::where('active', true)->orderBy('annee', 'desc')->get(['id', 'annee']),
            'mois' => DossierMois::with('annee')->where('active', true)->orderBy('mois')->get(['id', 'annee_id', 'mois', 'nom_mois']),
            'user' => $user,
            'permissions' => [
                'can_modify' => true,
                'can_delete' => true,
                'can_download' => true,
                'can_view' => true,
            ]
        ]);
    }

    /**
     * Modifier une archive en attente/rejetée
     */
    public function update(Request $request, Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isArchiviste() || $archive->created_by !== $user->id) {
            abort(403, 'Vous n\'avez pas les droits pour modifier cette archive.');
        }

        if (!in_array($archive->validation_status, [Archive::STATUS_PENDING, Archive::STATUS_REJECTED])) {
            abort(403, 'Cette archive est déjà validée et ne peut pas être modifiée.');
        }

        $request->validate([
            'titre' => 'required|string|max:255',
            'reference' => 'required|string|unique:archives,reference,' . $archive->id,
            'dossier_id' => 'required|exists:dossiers,id',
            'date_document' => 'required|date',
            'description' => 'nullable|string',
            'mots_cles' => 'nullable|string|max:255',
        ]);

        $dossier = Dossier::with(['mois.annee'])->find($request->dossier_id);
        if ($dossier && $dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
            return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
        }

        $archive->update($request->only([
            'titre', 'reference', 'dossier_id', 'date_document', 'description', 'mots_cles'
        ]));

        return redirect()->back()->with('success', 'Archive mise à jour avec succès.');
    }

    /**
     * Supprimer une archive en attente/rejetée
     */
    public function destroy(Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isArchiviste() || $archive->created_by !== $user->id) {
            abort(403, 'Vous n\'avez pas les droits pour supprimer cette archive.');
        }

        if (!in_array($archive->validation_status, [Archive::STATUS_PENDING, Archive::STATUS_REJECTED])) {
            abort(403, 'Cette archive est déjà validée et ne peut pas être supprimée.');
        }

        if ($archive->fichier_path && Storage::disk('public')->exists($archive->fichier_path)) {
            Storage::disk('public')->delete($archive->fichier_path);
        }

        $archive->delete();
        return redirect()->back()->with('success', 'Archive supprimée avec succès.');
    }

    /**
     * Télécharger une archive en attente/rejetée
     */
    public function download(Archive $archive): StreamedResponse
    {
        $user = Auth::user();

        if (!$user->isArchiviste() || $archive->created_by !== $user->id) {
            abort(403, 'Vous n\'avez pas les droits pour télécharger ce document.');
        }

        if (!Storage::disk('public')->exists($archive->fichier_path)) {
            abort(404, 'Le fichier physique est introuvable.');
        }

        return Storage::disk('public')->download(
            $archive->fichier_path,
            $archive->fichier_nom_original
        );
    }

    /**
     * Visualiser une archive en attente/rejetée
     */
    public function viewFile(Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isArchiviste() || $archive->created_by !== $user->id) {
            abort(403, 'Vous n\'avez pas les droits pour visualiser ce document.');
        }

        if (!Storage::disk('public')->exists($archive->fichier_path)) {
            abort(404);
        }

        return response()->file(storage_path('app/public/' . $archive->fichier_path));
    }

    /**
     * Statistiques des archives en attente/rejetées
     */
    public function stats()
    {
        $user = Auth::user();

        if (!$user->isArchiviste()) {
            abort(403, 'Vous n\'avez pas les droits pour accéder à cette page.');
        }

        $stats = [
            'total_pending' => Archive::where('created_by', $user->id)
                ->where('validation_status', Archive::STATUS_PENDING)
                ->count(),
            'total_rejected' => Archive::where('created_by', $user->id)
                ->where('validation_status', Archive::STATUS_REJECTED)
                ->count(),
            'total_archives' => Archive::where('created_by', $user->id)->count(),
        ];

        return response()->json($stats);
    }
}
