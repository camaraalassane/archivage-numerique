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

        // Seul le Gestionnaire peut accéder à cette page
        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour accéder à cette page.');
        }

        $query = Archive::with(['dossier.mois.annee', 'createur', 'validateur'])
            ->where('validation_status', Archive::STATUS_PENDING);

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
