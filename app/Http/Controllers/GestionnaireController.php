<?php
// app/Http/Controllers/GestionnaireController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Dossier;
use App\Models\DossierAnnee;
use App\Models\DossierMois;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GestionnaireController extends Controller
{
    /**
     * Affiche toutes les archives en attente (tous les utilisateurs)
     */
    public function pendingArchives(Request $request)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour accéder à cette page.');
        }

        $query = Archive::with(['dossier.mois.annee', 'createur', 'validateur'])
            ->where('validation_status', Archive::STATUS_PENDING);

        $query->when($request->search, function ($q, $search) {
            $q->where(function ($sub) use ($search) {
                $sub->where('titre', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('mots_cles', 'like', "%{$search}%");
            });
        })
        ->when($request->dossier_id, fn($q, $d) => $q->where('dossier_id', $d))
        ->when($request->type, fn($q, $t) => $q->where('type_document', $t))
        ->when($request->created_by, fn($q, $u) => $q->where('created_by', $u))
        ->when($request->date_debut, fn($q, $dd) => $q->whereDate('date_document', '>=', $dd))
        ->when($request->date_fin, fn($q, $df) => $q->whereDate('date_document', '<=', $df));

        return Inertia::render('Gestionnaire/PendingArchives', [
            'filters' => $request->all(['search', 'dossier_id', 'type', 'created_by', 'date_debut', 'date_fin']),
            'archives' => $query->latest()->paginate(15)->withQueryString(),
            'dossiers' => Dossier::with(['mois.annee'])->orderBy('nom')->get(['id', 'nom', 'mois_id', 'couleur']),
            'type_documents' => Archive::select('type_document')->distinct()->pluck('type_document'),
            'users' => User::all(['id', 'name', 'email']),
            'user' => $user,
        ]);
    }

    /**
     * Valider une archive
     */
    public function validate(Request $request, Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour valider cette archive.');
        }

        $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        $archive->update([
            'validation_status' => Archive::STATUS_VALIDATED,
            'validated_by' => $user->id,
            'validated_at' => now(),
            'validation_comment' => $request->comment ?? 'Validé par le gestionnaire',
        ]);

        return redirect()->back()->with('success', 'Archive validée avec succès.');
    }

    /**
     * Rejeter une archive
     */
    public function reject(Request $request, Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour rejeter cette archive.');
        }

        $request->validate([
            'comment' => 'required|string|max:500',
        ]);

        $archive->update([
            'validation_status' => Archive::STATUS_REJECTED,
            'validated_by' => $user->id,
            'validated_at' => now(),
            'validation_comment' => $request->comment,
        ]);

        return redirect()->back()->with('success', 'Archive rejetée avec succès.');
    }

    /**
     * 🔥 VALIDER TOUTES LES ARCHIVES D'UN ARCHIVISTE OU UNE SÉLECTION
     */
    public function validateAll(Request $request)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour effectuer cette action.');
        }

        $request->validate([
            'archiviste_id' => 'nullable|integer|exists:users,id',
            'ids' => 'nullable|array',
            'ids.*' => 'integer|exists:archives,id',
        ]);

        $query = Archive::where('validation_status', Archive::STATUS_PENDING);

        if ($request->filled('ids') && count($request->ids) > 0) {
            $query->whereIn('id', $request->ids);
        } elseif ($request->filled('archiviste_id')) {
            $query->where('created_by', $request->archiviste_id);
        } else {
            return redirect()->back()->with('error', 'Aucune archive sélectionnée.');
        }

        $count = $query->count();

        if ($count === 0) {
            return redirect()->back()->with('error', 'Aucune archive en attente à valider.');
        }

        $query->update([
            'validation_status' => Archive::STATUS_VALIDATED,
            'validated_by' => $user->id,
            'validated_at' => now(),
            'validation_comment' => 'Validé en masse par le gestionnaire',
        ]);

        return redirect()->back()->with('success', "{$count} archive(s) validée(s) avec succès.");
    }

    /**
     * 🔥 REJETER TOUTES LES ARCHIVES D'UN ARCHIVISTE OU UNE SÉLECTION
     */
    public function rejectAll(Request $request)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour effectuer cette action.');
        }

        $request->validate([
            'archiviste_id' => 'nullable|integer|exists:users,id',
            'ids' => 'nullable|array',
            'ids.*' => 'integer|exists:archives,id',
            'comment' => 'nullable|string|max:500',
        ]);

        $query = Archive::where('validation_status', Archive::STATUS_PENDING);

        if ($request->filled('ids') && count($request->ids) > 0) {
            $query->whereIn('id', $request->ids);
        } elseif ($request->filled('archiviste_id')) {
            $query->where('created_by', $request->archiviste_id);
        } else {
            return redirect()->back()->with('error', 'Aucune archive sélectionnée.');
        }

        $count = $query->count();

        if ($count === 0) {
            return redirect()->back()->with('error', 'Aucune archive en attente à rejeter.');
        }

        $query->update([
            'validation_status' => Archive::STATUS_REJECTED,
            'validated_by' => $user->id,
            'validated_at' => now(),
            'validation_comment' => $request->comment ?? 'Rejeté en masse par le gestionnaire',
        ]);

        return redirect()->back()->with('success', "{$count} archive(s) rejetée(s).");
    }

    /**
     * 🔥 SUPPRIMER TOUTES LES ARCHIVES D'UN ARCHIVISTE OU UNE SÉLECTION
     */
    public function destroyAll(Request $request)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour effectuer cette action.');
        }

        $request->validate([
            'archiviste_id' => 'nullable|integer|exists:users,id',
            'ids' => 'nullable|array',
            'ids.*' => 'integer|exists:archives,id',
        ]);

        $query = Archive::where('validation_status', Archive::STATUS_PENDING);

        if ($request->filled('ids') && count($request->ids) > 0) {
            $query->whereIn('id', $request->ids);
        } elseif ($request->filled('archiviste_id')) {
            $query->where('created_by', $request->archiviste_id);
        } else {
            return redirect()->back()->with('error', 'Aucune archive sélectionnée.');
        }

        $archives = $query->get();
        $count = $archives->count();

        if ($count === 0) {
            return redirect()->back()->with('error', 'Aucune archive en attente à supprimer.');
        }

        foreach ($archives as $archive) {
            if ($archive->fichier_path && Storage::disk('public')->exists($archive->fichier_path)) {
                Storage::disk('public')->delete($archive->fichier_path);
            }
            $archive->delete();
        }

        return redirect()->back()->with('success', "{$count} archive(s) supprimée(s) avec succès.");
    }

    /**
     * Visualiser une archive
     */
    public function viewFile(Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour visualiser ce document.');
        }

        if (!Storage::disk('public')->exists($archive->fichier_path)) {
            abort(404);
        }

        return response()->file(storage_path('app/public/' . $archive->fichier_path));
    }

    /**
     * Télécharger une archive
     */
    public function download(Archive $archive): StreamedResponse
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
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
}
