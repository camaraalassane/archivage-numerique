<?php
// app/Http/Controllers/ArchiveController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Dossier;
use App\Models\DossierAnnee;
use App\Models\DossierMois;
use App\Models\User;
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
        if ($user->isArchiviste()) {
            $query->where('created_by', $user->id);
        } else if ($user->isDivision()) {
            $query->where('validation_status', Archive::STATUS_VALIDATED);
        }

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
     * Vérifier si un document existe déjà
     */
    private function checkDuplicate($reference, $dossierId, $fileName = null)
    {
        // 1. Vérification par référence exacte
        $existing = Archive::where('reference', $reference)
            ->where('dossier_id', $dossierId)
            ->first();

        if ($existing) {
            return [
                'exists' => true,
                'message' => "Un document avec la référence '{$reference}' existe déjà dans ce dossier.",
                'archive' => $existing
            ];
        }

        // 2. Vérification par nom de fichier (si fourni)
        if ($fileName) {
            $existingByName = Archive::where('fichier_nom_original', $fileName)
                ->where('dossier_id', $dossierId)
                ->first();

            if ($existingByName) {
                return [
                    'exists' => true,
                    'message' => "Un fichier nommé '{$fileName}' existe déjà dans ce dossier.",
                    'archive' => $existingByName
                ];
            }
        }

        // 3. Vérification par titre (approche plus souple)
        if ($fileName) {
            $titreBase = pathinfo($fileName, PATHINFO_FILENAME);
            if ($titreBase) {
                $existingByTitle = Archive::where('titre', 'LIKE', $titreBase . '%')
                    ->where('dossier_id', $dossierId)
                    ->first();

                if ($existingByTitle) {
                    return [
                        'exists' => true,
                        'message' => "Un document avec un titre similaire '{$existingByTitle->titre}' existe déjà dans ce dossier.",
                        'archive' => $existingByTitle
                    ];
                }
            }
        }

        return ['exists' => false];
    }

    /**
     * Vérifier les doublons pour plusieurs fichiers
     */
    public function checkDuplicates(Request $request)
    {
        $request->validate([
            'dossier_id' => 'required|exists:dossiers,id',
            'fichiers' => 'required|array',
            'fichiers.*' => 'string|max:255'
        ]);

        $duplicates = [];
        $dossierId = $request->dossier_id;
        $fileNames = $request->fichiers;

        foreach ($fileNames as $fileName) {
            // Vérification par nom de fichier
            $existing = Archive::where('fichier_nom_original', $fileName)
                ->where('dossier_id', $dossierId)
                ->first();

            if ($existing) {
                $duplicates[] = [
                    'file' => $fileName,
                    'reference' => $existing->reference,
                    'titre' => $existing->titre,
                    'existing_id' => $existing->id,
                    'status' => $existing->validation_status
                ];
            } else {
                // Vérification par titre
                $titreBase = pathinfo($fileName, PATHINFO_FILENAME);
                if ($titreBase) {
                    $existingByTitle = Archive::where('titre', 'LIKE', $titreBase . '%')
                        ->where('dossier_id', $dossierId)
                        ->first();

                    if ($existingByTitle) {
                        $duplicates[] = [
                            'file' => $fileName,
                            'reference' => $existingByTitle->reference,
                            'titre' => $existingByTitle->titre,
                            'existing_id' => $existingByTitle->id,
                            'status' => $existingByTitle->validation_status,
                            'warning' => 'Titre similaire'
                        ];
                    }
                }
            }
        }

        return response()->json([
            'duplicates' => $duplicates,
            'count' => count($duplicates)
        ]);
    }

    /**
     * Valider ou rejeter une archive
     */
    public function validateArchive(Request $request, Archive $archive)
    {
        $user = Auth::user();

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
        try {
            $user = Auth::user();

            if (!$user->isAdmin() && !$user->isGestionnaire() && !$user->isArchiviste()) {
                abort(403, 'Vous n\'avez pas les droits pour créer des archives.');
            }

            $validated = $request->validate([
                'titre' => 'required|string|max:255',
                'reference' => 'required|string|max:50',
                'dossier_id' => 'required|exists:dossiers,id',
                'date_document' => 'required|date',
                'fichier' => 'required|file|max:20480',
                'mots_cles' => 'nullable|string|max:255',
                'description' => 'nullable|string',
            ]);

            // 🔥 VÉRIFICATION DES DOUBLONS
            $reference = $validated['reference'];
            $dossierId = $validated['dossier_id'];
            $fileName = $request->file('fichier')->getClientOriginalName();

            $duplicateCheck = $this->checkDuplicate($reference, $dossierId, $fileName);

            if ($duplicateCheck['exists']) {
                \Log::warning('Tentative de doublon détectée', [
                    'reference' => $reference,
                    'dossier_id' => $dossierId,
                    'user' => $user->id,
                    'existing' => $duplicateCheck['archive']->id
                ]);

                return redirect()->back()->withErrors([
                    'doublon' => $duplicateCheck['message'] . ' Veuillez vérifier la référence ou le nom du fichier.'
                ])->withInput();
            }

            $dossier = Dossier::with(['mois.annee'])->find($request->dossier_id);
            if ($dossier && $dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
                return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
            }

            if (!$request->hasFile('fichier')) {
                return redirect()->back()->with('error', 'Aucun fichier sélectionné.');
            }

            $file = $request->file('fichier');

            $chemin = "archives/{$dossier->mois->annee->annee}/{$dossier->mois->mois}/{$dossier->nom}";
            $path = $file->store($chemin, 'archives');

            if (!$path) {
                return redirect()->back()->with('error', 'Erreur lors du stockage du fichier.');
            }

            $archive = Archive::create([
                'titre' => $request->titre,
                'reference' => $validated['reference'],
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

            return redirect()->back()->with('success', 'Document archivé avec succès. En attente de validation.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Erreur de validation:', ['errors' => $e->errors()]);
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'archivage:', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->with('error', 'Erreur lors de l\'archivage: ' . $e->getMessage());
        }
    }

    public function storeMultiple(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->isAdmin() && !$user->isGestionnaire() && !$user->isArchiviste()) {
                abort(403, 'Vous n\'avez pas les droits pour créer des archives.');
            }

            $validated = $request->validate([
                'dossier_id' => 'required|exists:dossiers,id',
                'date_document' => 'required|date',
                'fichiers' => 'required|array|min:1',
                'fichiers.*' => 'file|max:20480',
                'references' => 'nullable|array',
                'references.*' => 'nullable|string|max:50',
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
            $duplicates = [];
            $references = $request->input('references', []);

            foreach ($request->file('fichiers') as $index => $file) {
                $originalName = $file->getClientOriginalName();

                // 🔥 VÉRIFICATION DES DOUBLONS POUR CHAQUE FICHIER
                $rawRef = $references[$index] ?? pathinfo($originalName, PATHINFO_FILENAME);
                $cleanedRef = preg_replace('/[^A-Z0-9]/', '_', strtoupper($rawRef));

                // Référence temporaire pour vérification
                $microtimeToken = substr(str_replace('.', '', microtime(true)), -5);
                $tempRef = substr($cleanedRef, 0, 25) . '_' . $index . '_' . $microtimeToken;

                $duplicateCheck = $this->checkDuplicate($tempRef, $dossier->id, $originalName);

                if ($duplicateCheck['exists']) {
                    $duplicates[] = [
                        'file' => $originalName,
                        'message' => $duplicateCheck['message']
                    ];
                    $errors++;
                    continue;
                }

                // Génération de la référence unique
                $suffix = '_' . $index . '_' . $microtimeToken;
                $baseReference = substr($cleanedRef, 0, 50 - strlen($suffix));
                $reference = $baseReference . $suffix;
                $counter = 1;

                while (Archive::where('reference', $reference)->exists()) {
                    $extraSuffix = '_' . $index . '_' . $microtimeToken . '_' . $counter;
                    $reference = substr($baseReference, 0, 50 - strlen($extraSuffix)) . $extraSuffix;
                    $counter++;

                    if ($counter > 10) {
                        $finalSuffix = '_' . $index . '_' . sprintf("%04x", mt_rand(0, 0xffff));
                        $reference = substr($baseReference, 0, 50 - strlen($finalSuffix)) . $finalSuffix;
                        break;
                    }
                }

                $reference = substr($reference, 0, 50);

                try {
                    $chemin = "archives/{$dossier->mois->annee->annee}/{$dossier->mois->mois}/{$dossier->nom}";
                    $path = $file->store($chemin, 'archives');

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

            // Construction du message de retour
            $message = $imported . ' fichier(s) archivé(s) avec succès. En attente de validation.';

            if ($errors > 0) {
                $message .= ' ' . $errors . ' erreur(s) : ';
                if (count($duplicates) > 0) {
                    $message .= ' [DOUBLONS] ' . implode(' | ', array_column($duplicates, 'file'));
                }
                if (count($errorDetails) > 0) {
                    $message .= ' [ERREURS] ' . implode(' | ', $errorDetails);
                }
            }

            // Log des doublons
            if (count($duplicates) > 0) {
                \Log::warning('Doublons détectés lors de l\'import multiple', [
                    'dossier_id' => $dossier->id,
                    'duplicates' => $duplicates,
                    'user' => $user->id
                ]);
            }

            return redirect()->back()->with($imported > 0 ? 'success' : 'error', $message);

        } catch (\Exception $e) {
            \Log::error('Erreur storeMultiple:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function update(Request $request, Archive $archive)
    {
        try {
            $user = Auth::user();

            if ($user->isArchiviste() && $archive->created_by !== $user->id) {
                abort(403, 'Vous n\'avez pas le droit de modifier ce document.');
            }

            if ($user->isDivision()) {
                abort(403, 'Vous n\'avez pas les droits pour modifier des archives.');
            }

            $request->validate([
                'titre' => 'required|string|max:255',
                'reference' => 'required|string|unique:archives,reference,' . $archive->id,
                'dossier_id' => 'required|exists:dossiers,id',
                'date_document' => 'required|date',
                'mots_cles' => 'nullable|string|max:255',
                'description' => 'nullable|string',
            ]);

            $dossier = Dossier::with(['mois.annee'])->find($request->dossier_id);
            if ($dossier && $dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
                return redirect()->back()->with('error', 'Impossible : cette année est clôturée.');
            }

            $archive->update($request->only([
                'titre', 'reference', 'dossier_id', 'date_document', 'description', 'mots_cles', 'version'
            ]));

            return redirect()->back()->with('success', 'Document mis à jour avec succès');

        } catch (\Exception $e) {
            \Log::error('Erreur update:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function download(Archive $archive): StreamedResponse
    {
        if (!Storage::disk('archives')->exists($archive->fichier_path)) {
            abort(404, 'Le fichier physique est introuvable.');
        }
        return Storage::disk('archives')->download(
            $archive->fichier_path,
            $archive->fichier_nom_original
        );
    }

    public function viewFile(Archive $archive)
    {
        if (!Storage::disk('archives')->exists($archive->fichier_path)) {
            abort(404);
        }
        return response()->file(Storage::disk('archives')->path($archive->fichier_path));
    }

    public function destroy(Archive $archive)
    {
        try {
            $user = Auth::user();

            if (!$user->isAdmin() && !$user->isGestionnaire()) {
                abort(403, 'Vous n\'avez pas les droits pour supprimer des archives.');
            }

            if ($archive->fichier_path && Storage::disk('archives')->exists($archive->fichier_path)) {
                Storage::disk('archives')->delete($archive->fichier_path);
            }

            $archive->delete();
            return redirect()->back()->with('success', 'Archive supprimée avec succès.');

        } catch (\Exception $e) {
            \Log::error('Erreur destroy:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function export(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->canExport()) {
                abort(403, 'Vous n\'avez pas les droits pour exporter des archives.');
            }

            $query = Archive::query()->with(['dossier.mois.annee', 'createur']);

            if ($user->isArchiviste()) {
                $query->where('created_by', $user->id);
            } else if ($user->isDivision()) {
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

        } catch (\Exception $e) {
            \Log::error('Erreur export:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur lors de l\'export.');
        }
    }

    public function toggleFavorite(Archive $archive)
    {
        try {
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

        } catch (\Exception $e) {
            \Log::error('Erreur toggleFavorite:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function newVersion(Request $request, Archive $archive)
    {
        try {
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
                $path = $file->store($chemin, 'archives');

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

        } catch (\Exception $e) {
            \Log::error('Erreur newVersion:', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }
}
