<?php
// app/Http/Controllers/ArchiveController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Dossier;
use App\Models\DossierAnnee;
use App\Models\DossierMois;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Auth;

class ArchiveController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Archive::query()
            ->with(['dossier.mois.annee', 'createur', 'validateur']);

        // === FILTRES PAR RÔLE ===
        // Archiviste : voit UNIQUEMENT ses propres archives (en attente, validées, rejetées)
        if ($user->isArchiviste()) {
            $query->where('created_by', $user->id);
        }
        // Division : voit uniquement les archives validées
        else if ($user->isDivision()) {
            $query->where('validation_status', Archive::STATUS_VALIDATED);
        }
        // Admin et Gestionnaire : voient tout

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

        return Inertia::render('Archives/Index', [
            'filters' => $request->all(['search', 'dossier_id', 'type', 'date_debut', 'date_fin', 'validation_status']),
            'archives' => $query->latest()->paginate(10)->withQueryString(),
            'dossiers' => Dossier::with(['mois.annee'])->orderBy('nom')->get(['id', 'nom', 'mois_id', 'couleur']),
            'type_documents' => Archive::select('type_document')->distinct()->pluck('type_document'),
            'annees' => DossierAnnee::where('active', true)->orderBy('annee', 'desc')->get(['id', 'annee']),
            'mois' => DossierMois::with('annee')->where('active', true)->orderBy('mois')->get(['id', 'annee_id', 'mois', 'nom_mois']),
            'user' => $user,
            'permissions' => [
                'can_validate' => $user->canValidateArchives(),
                'can_manage_all' => $user->canManageAll(),
                'can_manage_users' => $user->canManageUsers(),
                'can_export' => $user->canExport(),
                'can_manage_dossiers' => $user->canManageDossiers(),
                'can_view_all' => $user->canViewAllArchives(),
                'is_division' => $user->isDivision(),
                'is_archiviste' => $user->isArchiviste(),
                'can_modify_archives' => $user->isArchiviste() || $user->isGestionnaire() || $user->isAdmin(),
            ]
        ]);
    }

    /**
     * Valider ou rejeter une archive
     */
    public function validateArchive(Request $request, Archive $archive)
    {
        $user = Auth::user();

        // Seul Admin ou Gestionnaire peuvent valider
        if (!$user->canValidateArchives()) {
            abort(403, 'Vous n\'avez pas les droits pour valider des archives.');
        }

        $request->validate([
            'status' => 'required|in:validated,rejected',
            'comment' => 'nullable|string|max:500',
        ]);

        $archive->update([
            'validation_status' => $request->status,
            'validated_by' => $user->id,
            'validated_at' => now(),
            'validation_comment' => $request->comment,
        ]);

        $statusLabel = $request->status === 'validated' ? 'validée' : 'rejetée';
        return redirect()->back()->with('success', "Archive {$statusLabel} avec succès.");
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        // Seul Admin, Gestionnaire ou Archiviste peuvent créer
        if (!$user->isAdmin() && !$user->isGestionnaire() && !$user->isArchiviste()) {
            abort(403, 'Vous n\'avez pas les droits pour créer des archives.');
        }

        $request->validate([
            'titre' => 'required|string|max:255',
            'reference' => 'required|string|unique:archives,reference',
            'dossier_id' => 'required|exists:dossiers,id',
            'date_document' => 'required|date',
            'fichier' => 'required|file|max:20480',
            'mots_cles' => 'nullable|string|max:255',
        ]);

        $dossier = Dossier::with(['mois.annee'])->find($request->dossier_id);
        if ($dossier && $dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
            return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
        }

        if ($request->hasFile('fichier')) {
            $file = $request->file('fichier');

            $chemin = "archives/{$dossier->mois->annee->annee}/{$dossier->mois->mois}/{$dossier->nom}";
            $path = $file->store($chemin, 'public');

            $archive = Archive::create([
                'titre' => $request->titre,
                'reference' => $request->reference,
                'description' => $request->description,
                'dossier_id' => $request->dossier_id,
                'type_document' => $file->getClientOriginalExtension(),
                'fichier_path' => $path,
                'fichier_nom_original' => $file->getClientOriginalName(),
                'fichier_taille' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'date_document' => $request->date_document,
                'mots_cles' => $request->mots_cles,
                'created_by' => $user->id,
                'validation_status' => Archive::STATUS_PENDING,
                'metadata' => [
                    'ip_adresse' => $request->ip(),
                    'navigateur' => $request->header('User-Agent')
                ]
            ]);
        }

        return redirect()->back()->with('success', 'Document archivé avec succès. En attente de validation.');
    }

    public function storeMultiple(Request $request)
    {
        $user = Auth::user();

        // Seul Admin, Gestionnaire ou Archiviste peuvent créer
        if (!$user->isAdmin() && !$user->isGestionnaire() && !$user->isArchiviste()) {
            abort(403, 'Vous n\'avez pas les droits pour créer des archives.');
        }

        $request->validate([
            'dossier_id' => 'required|exists:dossiers,id',
            'date_document' => 'required|date',
            'fichiers' => 'required|array|min:1',
            'fichiers.*' => 'file|max:20480',
            'description' => 'nullable|string',
            'mots_cles' => 'nullable|string|max:255',
        ]);

        $dossier = Dossier::with(['mois.annee'])->find($request->dossier_id);
        if (!$dossier) {
            return redirect()->back()->with('error', 'Dossier introuvable.');
        }

        if ($dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
            return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
        }

        $imported = 0;
        $errors = 0;
        $errorDetails = [];

        foreach ($request->file('fichiers') as $file) {
            $originalName = $file->getClientOriginalName();

            $reference = preg_replace('/[^A-Z0-9]/', '_', strtoupper(pathinfo($originalName, PATHINFO_FILENAME)));

            if (Archive::where('reference', $reference)->exists()) {
                $errors++;
                $errorDetails[] = $originalName . ' (doublon)';
                continue;
            }

            try {
                $chemin = "archives/{$dossier->mois->annee->annee}/{$dossier->mois->mois}/{$dossier->nom}";
                $path = $file->store($chemin, 'public');

                Archive::create([
                    'titre' => pathinfo($originalName, PATHINFO_FILENAME),
                    'reference' => $reference,
                    'description' => $request->description,
                    'dossier_id' => $dossier->id,
                    'type_document' => $file->getClientOriginalExtension(),
                    'fichier_path' => $path,
                    'fichier_nom_original' => $originalName,
                    'fichier_taille' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'date_document' => $request->date_document,
                    'mots_cles' => $request->mots_cles,
                    'created_by' => $user->id,
                    'validation_status' => Archive::STATUS_PENDING,
                    'metadata' => [
                        'ip_adresse' => $request->ip(),
                        'navigateur' => $request->header('User-Agent')
                    ]
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors++;
                $errorDetails[] = $originalName . ' : ' . $e->getMessage();
            }
        }

        $message = $imported . ' fichier(s) archivé(s) avec succès. En attente de validation.';
        if ($errors > 0) {
            $message .= ' ' . $errors . ' erreur(s) : ' . implode(' | ', $errorDetails);
        }

        return redirect()->back()->with($imported > 0 ? 'success' : 'error', $message);
    }

    public function update(Request $request, Archive $archive)
    {
        $user = Auth::user();

        // Archiviste : peut modifier ses propres archives uniquement
        if ($user->isArchiviste() && $archive->created_by !== $user->id) {
            abort(403, 'Vous n\'avez pas le droit de modifier ce document.');
        }

        // Division : ne peut pas modifier
        if ($user->isDivision()) {
            abort(403, 'Vous n\'avez pas les droits pour modifier des archives.');
        }

        $request->validate([
            'titre' => 'required|string|max:255',
            'reference' => 'required|string|unique:archives,reference,' . $archive->id,
            'dossier_id' => 'required|exists:dossiers,id',
            'date_document' => 'required|date',
            'mots_cles' => 'nullable|string|max:255',
        ]);

        $dossier = Dossier::with(['mois.annee'])->find($request->dossier_id);
        if ($dossier && $dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
            return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
        }

        $archive->update($request->only([
            'titre', 'reference', 'dossier_id', 'date_document', 'description', 'mots_cles', 'version'
        ]));

        return redirect()->back()->with('success', 'Document mis à jour avec succès');
    }

 public function download(Archive $archive): StreamedResponse
{
    // Pas de restriction - tout le monde peut télécharger
    if (!Storage::disk('public')->exists($archive->fichier_path)) {
        abort(404, 'Le fichier physique est introuvable.');
    }
    return Storage::disk('public')->download(
        $archive->fichier_path,
        $archive->fichier_nom_original
    );
}



public function viewFile(Archive $archive)
{
    // Pas de restriction - tout le monde peut visualiser
    if (!Storage::disk('public')->exists($archive->fichier_path)) {
        abort(404);
    }
    return response()->file(storage_path('app/public/' . $archive->fichier_path));
}


    public function destroy(Archive $archive)
    {
        $user = Auth::user();

        // Seul Admin ou Gestionnaire peuvent supprimer
        if (!$user->isAdmin() && !$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour supprimer des archives.');
        }

        if ($archive->fichier_path && Storage::disk('public')->exists($archive->fichier_path)) {
            Storage::disk('public')->delete($archive->fichier_path);
        }

        $archive->delete();
        return redirect()->back()->with('success', 'Archive supprimée avec succès.');
    }

    public function export(Request $request)
    {
        $user = Auth::user();

        // Seul Admin ou Gestionnaire peuvent exporter
        if (!$user->canExport()) {
            abort(403, 'Vous n\'avez pas les droits pour exporter des archives.');
        }

        $query = Archive::query()
            ->with(['dossier.mois.annee', 'createur']);

        // Archiviste : exporte uniquement ses propres archives
        if ($user->isArchiviste()) {
            $query->where('created_by', $user->id);
        }
        // Division : exporte uniquement les archives validées
        else if ($user->isDivision()) {
            $query->where('validation_status', Archive::STATUS_VALIDATED);
        }

        $query->when($request->search, function ($q, $search) {
            $q->where(function ($sub) use ($search) {
                $sub->where('titre', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        })
        ->when($request->dossier_id, fn($q, $d) => $q->where('dossier_id', $d))
        ->when($request->date_debut, fn($q, $dd) => $q->whereDate('date_document', '>=', $dd))
        ->when($request->date_fin, fn($q, $df) => $q->whereDate('date_document', '<=', $df));

        $archives = $query->latest()->get();

        $fileName = 'export_archives_' . now()->format('d_m_Y') . '.csv';

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        $columns = ['Référence', 'Titre', 'Année', 'Mois', 'Dossier', 'Date Document', 'Type', 'Mots-clés', 'Statut', 'Validé par', 'Date validation'];

        $callback = function() use($archives, $columns) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, $columns, ';');

            foreach ($archives as $archive) {
                fputcsv($file, [
                    $archive->reference,
                    $archive->titre,
                    $archive->dossier->mois->annee->annee ?? 'N/A',
                    $archive->dossier->mois->nom_mois ?? 'N/A',
                    $archive->dossier->nom ?? 'N/A',
                    $archive->date_document,
                    $archive->type_document,
                    $archive->mots_cles,
                    $archive->status_label,
                    $archive->validateur->name ?? 'N/A',
                    $archive->validated_at ?? 'N/A',
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function toggleFavorite(Archive $archive)
    {
        $user = Auth::user();

        if ($user->isArchiviste() && $archive->created_by !== $user->id) {
            abort(403, 'Vous n\'avez pas le droit de modifier ce document.');
        }

        if ($user->isDivision()) {
            abort(403, 'Vous n\'avez pas les droits pour modifier des archives.');
        }

        $archive->est_favori = !$archive->est_favori;
        $archive->save();

        return redirect()->back()->with('success', $archive->est_favori ? 'Ajouté aux favoris' : 'Retiré des favoris');
    }

    public function newVersion(Request $request, Archive $archive)
    {
        $user = Auth::user();

        if ($user->isArchiviste() && $archive->created_by !== $user->id) {
            abort(403, 'Vous n\'avez pas le droit de modifier ce document.');
        }

        if ($user->isDivision()) {
            abort(403, 'Vous n\'avez pas les droits pour modifier des archives.');
        }

        $request->validate([
            'fichier' => 'required|file|max:20480',
        ]);

        if ($request->hasFile('fichier')) {
            $file = $request->file('fichier');

            $newVersion = $archive->version + 1;

            $dossier = Dossier::with(['mois.annee'])->find($archive->dossier_id);
            if ($dossier && $dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
                return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
            }

            $chemin = "archives/{$dossier->mois->annee->annee}/{$dossier->mois->mois}/{$dossier->nom}";
            $path = $file->store($chemin, 'public');

            $archive->update([
                'fichier_path' => $path,
                'fichier_nom_original' => $file->getClientOriginalName(),
                'fichier_taille' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'version' => $newVersion,
                'type_document' => $file->getClientOriginalExtension(),
            ]);

            return redirect()->back()->with('success', 'Nouvelle version ajoutée (v' . $newVersion . ')');
        }

        return redirect()->back()->with('error', 'Aucun fichier fourni.');
    }
}
