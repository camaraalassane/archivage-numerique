<?php
// app/Http/Controllers/ImportController.php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Dossier;
use App\Models\DossierAnnee;
use App\Models\DossierMois;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Smalot\PdfParser\Parser as PdfParser;

class ImportController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Seul Admin peut importer
        if (!$user->isAdmin()) {
            abort(403, 'Vous n\'avez pas les droits pour accéder à l\'importation.');
        }

        return Inertia::render('Import/Index', [
            'annees' => DossierAnnee::where('active', true)->orderBy('annee', 'desc')->get(['id', 'annee']),
            'mois' => DossierMois::with('annee')->where('active', true)->orderBy('mois')->get(['id', 'annee_id', 'mois', 'nom_mois']),
            'dossiers' => Dossier::with(['mois.annee'])->where('active', true)->orderBy('nom')->get(['id', 'nom', 'mois_id', 'couleur']),
            'user' => $user,
            'permissions' => [
                'can_import' => $user->isAdmin(),
            ]
        ]);
    }

    private function getFileCreationDate(string $fullPath): ?array
    {
        try {
            if (!file_exists($fullPath)) return null;
            $timestamp = filemtime($fullPath) ?: filectime($fullPath);
            if (!$timestamp) return null;
            $year = (int)date('Y', $timestamp);
            $month = (int)date('n', $timestamp);
            $day = (int)date('j', $timestamp);
            if (checkdate($month, $day, $year) && $year >= 2000 && $year <= 2100) {
                return ['date' => date('Y-m-d', $timestamp), 'year' => $year, 'month' => $month];
            }
            return null;
        } catch (\Exception $e) { return null; }
    }

    private function detectDateFromPdfContent(string $fullPath): ?array
    {
        try {
            if (!file_exists($fullPath) || !is_readable($fullPath)) return null;
            $parser = new PdfParser();
            $pdf = $parser->parseFile($fullPath);
            $text = mb_substr($pdf->getText(), 0, 3000);
            if (empty($text)) return null;

            $patterns = [
                '/G[ée]n[ée]r[ée]\s+le\s+(\d{1,2})\/(\d{1,2})\/(\d{4})/iu',
                '/Date\s*[:]\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i',
                '/(\d{1,2})\/(\d{1,2})\/(\d{4})/',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $m)) {
                    [$full, $d, $mo, $y] = $m;
                    if (checkdate((int)$mo, (int)$d, (int)$y) && (int)$y >= 2000 && (int)$y <= 2100) {
                        return ['date' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'year' => (int)$y, 'month' => (int)$mo];
                    }
                }
            }
            if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
                [$full, $y, $mo, $d] = $m;
                if (checkdate((int)$mo, (int)$d, (int)$y) && (int)$y >= 2000 && (int)$y <= 2100) {
                    return ['date' => "$y-$mo-$d", 'year' => (int)$y, 'month' => (int)$mo];
                }
            }
            return null;
        } catch (\Exception $e) { return null; }
    }

    private function detectDateFromFilename(string $filename): ?array
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/(\d{4})[-_](\d{2})[-_](\d{2})/', $name, $m)) {
            [$full, $y, $mo, $d] = $m;
            if (checkdate((int)$mo, (int)$d, (int)$y))
                return ['date' => "$y-$mo-$d", 'year' => (int)$y, 'month' => (int)$mo];
        }
        if (preg_match('/(?<!\d)(\d{4})(\d{2})(\d{2})(?!\d)/', $name, $m)) {
            [$full, $y, $mo, $d] = $m;
            if ((int)$y >= 2000 && (int)$y <= 2100 && checkdate((int)$mo, (int)$d, (int)$y))
                return ['date' => "$y-$mo-$d", 'year' => (int)$y, 'month' => (int)$mo];
        }
        return null;
    }

    private function detectDate(string $fullPath, string $filename, string $extension): ?array
    {
        return $this->getFileCreationDate($fullPath)
            ?? ($extension === 'pdf' ? $this->detectDateFromPdfContent($fullPath) : null)
            ?? $this->detectDateFromFilename($filename);
    }

    private function isFileDuplicate(string $fullPath, string $filename): bool
    {
        if (Archive::where('fichier_nom_original', $filename)->exists()) return true;
        $reference = preg_replace('/[^A-Z0-9]/', '_', strtoupper(pathinfo($filename, PATHINFO_FILENAME)));
        return Archive::where('reference', $reference)->exists();
    }

    private function generateUniqueReference(string $filename): string
    {
        $base = substr(preg_replace('/[^A-Z0-9]/', '_', strtoupper(pathinfo($filename, PATHINFO_FILENAME))), 0, 40);
        $reference = $base . '_' . time();
        $counter = 1;
        while (Archive::where('reference', $reference)->exists()) {
            $reference = $base . '_' . time() . '_' . $counter++;
            if ($counter > 10) { $reference = $base . '_' . uniqid(); break; }
        }
        return $reference;
    }

    private function extractFolderName(string $relativePath): string
    {
        $parts = explode('/', $relativePath);
        return count($parts) > 1 ? $parts[0] : 'Racine';
    }

    public function scanDirectory(Request $request)
    {
        $user = Auth::user();

        // Seul Admin peut scanner
        if (!$user->isAdmin()) {
            return response()->json(['error' => 'Accès non autorisé'], 403);
        }

        $path = $request->input('path');
        if (empty($path) || !is_dir($path)) {
            return response()->json(['error' => 'Chemin invalide: ' . $path], 400);
        }

        $path = str_replace('\\', '/', rtrim($path, '/'));
        $files = [];
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $allowedExtensions)) {
                    $fullFilePath = str_replace('\\', '/', $file->getPathname());
                    $relativePath = ltrim(str_replace($path, '', $fullFilePath), '/');
                    $folderName = $this->extractFolderName($relativePath);
                    $detected = $this->detectDate($fullFilePath, $file->getFilename(), $extension);
                    $files[] = [
                        'name' => $file->getFilename(),
                        'path' => $relativePath,
                        'folder' => $folderName,
                        'extension' => $extension,
                        'size' => $file->getSize(),
                        'exists' => $this->isFileDuplicate($fullFilePath, $file->getFilename()),
                        'detected_date' => $detected['date'] ?? null,
                        'detected_year' => $detected['year'] ?? null,
                        'detected_month' => $detected['month'] ?? null,
                    ];
                }
            }
        }

        $filesByFolder = [];
        foreach ($files as $file) {
            $filesByFolder[$file['folder']][] = $file;
        }

        return response()->json([
            'files' => $files,
            'files_by_folder' => $filesByFolder,
            'folders' => array_keys($filesByFolder),
            'total' => count($files),
            'base_path' => $path
        ]);
    }

    public function importFiles(Request $request)
    {
        $user = Auth::user();

        // Seul Admin peut importer
        if (!$user->isAdmin()) {
            return response()->json(['error' => 'Accès non autorisé'], 403);
        }

        $files = $request->input('files', []);
        $basePath = str_replace('\\', '/', rtrim($request->input('base_path'), '/'));
        $fallbackDate = $request->input('date_document') ?: date('Y-m-d');

        $folderMapping = $request->input('folder_mapping', []);

        if (empty($files)) {
            return response()->json(['error' => 'Aucun fichier à importer'], 400);
        }

        if (empty($folderMapping)) {
            return response()->json(['error' => 'Aucun mapping de dossier fourni'], 400);
        }

        $imported = 0;
        $errors = 0;
        $duplicates = 0;
        $results = [];

        $filesByFolder = [];
        foreach ($files as $file) {
            $folder = $file['folder'] ?? 'Racine';
            $filesByFolder[$folder][] = $file;
        }

        foreach ($filesByFolder as $folderName => $folderFiles) {
            $targetDossierId = $folderMapping[$folderName] ?? null;

            if (!$targetDossierId) {
                continue;
            }

            $dossier = Dossier::with(['mois.annee'])->find($targetDossierId);
            if (!$dossier) {
                foreach ($folderFiles as $file) {
                    $errors++;
                    $results[] = [
                        'file' => $file['name'],
                        'success' => false,
                        'is_duplicate' => false,
                        'error' => "Dossier cible introuvable (id: $targetDossierId)",
                    ];
                }
                continue;
            }

            if ($dossier->mois && $dossier->mois->annee && $dossier->mois->annee->cloturee) {
                foreach ($folderFiles as $file) {
                    $errors++;
                    $results[] = [
                        'file' => $file['name'],
                        'success' => false,
                        'is_duplicate' => false,
                        'error' => "Année clôturée — impossible d'importer dans \"{$dossier->nom}\"",
                    ];
                }
                continue;
            }

            foreach ($folderFiles as $file) {
                $result = $this->importSingleFile($file, $basePath, $dossier, $fallbackDate);
                $results[] = $result;
                if ($result['success']) $imported++;
                elseif ($result['is_duplicate']) $duplicates++;
                else $errors++;
            }
        }

        return response()->json([
            'imported' => $imported,
            'errors' => $errors,
            'duplicates' => $duplicates,
            'results' => $results,
        ]);
    }

    private function importSingleFile(array $file, string $basePath, Dossier $dossier, string $fallbackDate): array
    {
        $fullPath = str_replace('\\', '/', $basePath . '/' . $file['path']);

        if (!file_exists($fullPath)) {
            return ['file' => $file['name'], 'success' => false, 'is_duplicate' => false, 'error' => 'Fichier introuvable: ' . $fullPath];
        }

        $dateDocument = $file['detected_date'] ?? null;
        if (empty($dateDocument)) {
            $detected = $this->detectDate($fullPath, $file['name'], $file['extension']);
            $dateDocument = $detected['date'] ?? $fallbackDate;
        }

        if ($this->isFileDuplicate($fullPath, $file['name'])) {
            return ['file' => $file['name'], 'success' => false, 'is_duplicate' => true, 'error' => 'Doublon'];
        }

        try {
            $storagePath = 'archives/' . $dossier->mois->annee->annee . '/' . $dossier->mois->mois . '/' . $dossier->nom;
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
            $fullStoragePath = $storagePath . '/' . $fileName;

            Storage::disk('public')->makeDirectory($storagePath);
            $content = file_get_contents($fullPath);
            if ($content === false) throw new \Exception('Impossible de lire le fichier');
            Storage::disk('public')->put($fullStoragePath, $content);

            $reference = $this->generateUniqueReference($file['name']);

            $archive = Archive::create([
                'titre' => pathinfo($file['name'], PATHINFO_FILENAME),
                'reference' => $reference,
                'dossier_id' => $dossier->id,
                'type_document' => $file['extension'],
                'fichier_path' => $fullStoragePath,
                'fichier_nom_original' => $file['name'],
                'fichier_taille' => $file['size'],
                'mime_type' => mime_content_type($fullPath) ?: 'application/octet-stream',
                'date_document' => $dateDocument,
                'created_by' => Auth::id() ?? 1,
                'validation_status' => Archive::STATUS_PENDING,
            ]);

            return [
                'file' => $file['name'],
                'success' => true,
                'is_duplicate' => false,
                'date_document' => $dateDocument,
                'reference' => $reference,
                'dossier' => $dossier->nom,
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) return ['file' => $file['name'], 'success' => false, 'is_duplicate' => true, 'error' => 'Doublon (référence déjà existante)'];
            return ['file' => $file['name'], 'success' => false, 'is_duplicate' => false, 'error' => 'Erreur BDD: ' . $e->getMessage()];
        } catch (\Exception $e) {
            return ['file' => $file['name'], 'success' => false, 'is_duplicate' => false, 'error' => $e->getMessage()];
        }
    }
}
