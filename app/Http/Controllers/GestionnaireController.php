<?php
// app/Http/Controllers/GestionnaireController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GestionnaireController extends Controller
{
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

        $pendingArchives = $query->latest()->get();
        $archivistes = User::whereIn('id', $pendingArchives->pluck('created_by')->unique())->get(['id', 'name']);

        return Inertia::render('Gestionnaire/PendingArchives', [
            'pendingArchives' => $pendingArchives,
            'archivistes' => $archivistes,
            'filters' => $request->all(['search', 'dossier_id', 'type', 'created_by', 'date_debut', 'date_fin']),
            'user' => $user,
        ]);
    }

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

    public function validateAll(Request $request)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour effectuer cette action.');
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:archives,id',
        ]);

        $count = Archive::whereIn('id', $request->ids)
            ->where('validation_status', Archive::STATUS_PENDING)
            ->update([
                'validation_status' => Archive::STATUS_VALIDATED,
                'validated_by' => $user->id,
                'validated_at' => now(),
                'validation_comment' => 'Validé en masse par le gestionnaire',
            ]);

        return redirect()->back()->with('success', "{$count} archive(s) validée(s) avec succès.");
    }

    public function rejectAll(Request $request)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour effectuer cette action.');
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:archives,id',
            'comment' => 'nullable|string|max:500',
        ]);

        $count = Archive::whereIn('id', $request->ids)
            ->where('validation_status', Archive::STATUS_PENDING)
            ->update([
                'validation_status' => Archive::STATUS_REJECTED,
                'validated_by' => $user->id,
                'validated_at' => now(),
                'validation_comment' => $request->comment ?? 'Rejeté en masse par le gestionnaire',
            ]);

        return redirect()->back()->with('success', "{$count} archive(s) rejetée(s).");
    }

    public function destroyAll(Request $request)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour effectuer cette action.');
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:archives,id',
        ]);

        $archives = Archive::whereIn('id', $request->ids)
            ->where('validation_status', Archive::STATUS_PENDING)
            ->get();

        $count = 0;
        foreach ($archives as $archive) {
            if ($archive->fichier_path && Storage::disk('public')->exists($archive->fichier_path)) {
                Storage::disk('public')->delete($archive->fichier_path);
            }
            $archive->delete();
            $count++;
        }

        return redirect()->back()->with('success', "{$count} archive(s) supprimée(s) avec succès.");
    }

    public function destroy(Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour supprimer cette archive.');
        }

        if ($archive->fichier_path && Storage::disk('public')->exists($archive->fichier_path)) {
            Storage::disk('public')->delete($archive->fichier_path);
        }

        $archive->delete();

        return redirect()->back()->with('success', 'Archive supprimée avec succès.');
    }

    public function viewFile(Archive $archive)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour visualiser ce document.');
        }

        if (!Storage::disk('public')->exists($archive->fichier_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($archive->fichier_path));
    }

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

    public function stats(Request $request)
    {
        $user = Auth::user();

        if (!$user->isGestionnaire()) {
            abort(403, 'Vous n\'avez pas les droits pour accéder aux statistiques.');
        }

        $stats = [
            'total_pending' => Archive::where('validation_status', Archive::STATUS_PENDING)->count(),
            'total_validated' => Archive::where('validation_status', Archive::STATUS_VALIDATED)->count(),
            'total_rejected' => Archive::where('validation_status', Archive::STATUS_REJECTED)->count(),
            'total_archives' => Archive::count(),
        ];

        return Inertia::render('Gestionnaire/Stats', [
            'stats' => $stats,
            'user' => $user,
        ]);
    }
}
