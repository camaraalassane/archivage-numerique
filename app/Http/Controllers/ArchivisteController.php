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
use Illuminate\Support\Facades\Cache;

class ArchivisteController extends Controller
{
    private function invalidateCaches(): void
    {
        Cache::forget('dashboard_tree_data');
        Cache::forget('stats_global');
        Cache::forget('distinct_archive_types');
    }

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

        $query = Archive::with([
            'dossier:id,nom,mois_id,couleur',
            'dossier.mois:id,nom_mois,annee_id,mois',
            'dossier.mois.annee:id,annee',
            'createur:id,name',
            'validateur:id,name'
        ])
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

        $typeDocuments = Cache::remember('distinct_archive_types', 300, function () {
            return Archive::select('type_document')->distinct()->pluck('type_document');
        });

        return Inertia::render('Archiviste/PendingRejected', [
            'filters' => $request->all(['search', 'dossier_id', 'type', 'date_debut', 'date_fin', 'validation_status']),
            'archives' => $query->latest()->paginate(15)->withQueryString(),
            'dossiers' => Dossier::with(['mois.annee'])->orderBy('nom')->get(['id', 'nom', 'mois_id', 'couleur']),
            'type_documents' => $typeDocuments,
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
            'fichier' => 'nullable|file|mimes:pdf,jpg,jpeg,png,docx|max:10240', // 10MB
        ]);

        $dossier = Dossier::with(['mois.annee'])->find($request->dossier_id);
        if ($dossier && $dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
            return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
        }

        $data = $request->only([
            'titre', 'reference', 'dossier_id', 'date_document', 'description', 'mots_cles'
        ]);

        if ($request->hasFile('fichier')) {
            $file = $request->file('fichier');
            
            // Delete old file
            if ($archive->fichier_path && Storage::disk('archives')->exists($archive->fichier_path)) {
                Storage::disk('archives')->delete($archive->fichier_path);
            }

            // Store new file
            $baseDir = $archive->type_document_confidentiel == 1 ? 'archives_confidentielles' : 'archives';
            $chemin = "{$baseDir}/{$dossier->mois->annee->annee}/{$dossier->mois->mois}/{$dossier->nom}";
            $path = $file->store($chemin, 'public');

            $data['fichier_path'] = $path;
            $data['fichier_nom_original'] = $file->getClientOriginalName();
            $data['fichier_taille'] = $file->getSize();
            $data['mime_type'] = $file->getMimeType();
            $data['type_document'] = $file->getClientOriginalExtension();
        }

        $archive->update($data);

        $this->invalidateCaches();

        return redirect()->back()->with('success', 'Archive mise à jour avec succès.');
    }

    /**
     * Resoumettre une archive rejetée pour validation
     */
    public function resubmit(Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isArchiviste() || $archive->created_by !== $user->id) {
            abort(403, 'Vous n\'avez pas les droits pour resoumettre cette archive.');
        }

        if (!$archive->isRejected()) {
            return redirect()->back()->with('error', 'Seules les archives rejetées peuvent être resoumises.');
        }

        $archive->update([
            'validation_status' => Archive::STATUS_PENDING,
            'validation_comment' => null,
            'validated_by' => null,
            'validated_at' => null
        ]);

        $this->invalidateCaches();

        \App\Models\ActivityLog::log('archive_resubmitted', "A resoumis l'archive rejetée {$archive->reference} pour validation.");

        return redirect()->back()->with('success', 'Archive resoumise pour validation avec succès.');
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

        if ($archive->fichier_path && Storage::disk('archives')->exists($archive->fichier_path)) {
            Storage::disk('archives')->delete($archive->fichier_path);
        }

        $archive->delete();

        $this->invalidateCaches();

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

        if (!Storage::disk('archives')->exists($archive->fichier_path)) {
            abort(404, 'Le fichier physique est introuvable.');
        }

        return Storage::disk('archives')->download(
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

        if (!Storage::disk('archives')->exists($archive->fichier_path)) {
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
