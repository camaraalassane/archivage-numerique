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
        return $this->detectDateFromFilename($filename)
            ?? ($extension === 'pdf' ? $this->detectDateFromPdfContent($fullPath) : null)
            ?? $this->getFileCreationDate($fullPath);
    }

    private function isFileDuplicate(string $filename, int $size, int $dossierId): bool
    {
        return Archive::where('fichier_nom_original', $filename)
            ->where('fichier_taille', $size)
            ->where('dossier_id', $dossierId)
            ->exists();
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
        $parts = explode('/', str_replace('\\', '/', $relativePath));
        
        // Si le chemin contient au moins 3 éléments (ex: 01_Janvier / Dossier_RH / ... / fichier.pdf)
        if (count($parts) >= 3) {
            return $parts[1]; // Le dossier cible est le 2ème niveau
        }
        // Si le chemin contient 2 éléments (ex: Dossier_RH / fichier.pdf)
        if (count($parts) == 2) {
            return $parts[0];
        }
        
        return 'Racine';
    }

    private function extractMonthName(string $relativePath): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $relativePath));
        // Le mois est toujours le 1er niveau de dossier (ex: 01_Janvier / Dossier_RH / fichier.pdf)
        return count($parts) >= 2 ? $parts[0] : null;
    }

    private function parseMonthNumber(?string $monthName): ?int
    {
        if (!$monthName) return null;
        
        // Try to find a number in the string (ex: "01_Janvier" -> 1)
        if (preg_match('/^0?(\d+)/', $monthName, $matches)) {
            $num = (int)$matches[1];
            if ($num >= 1 && $num <= 12) return $num;
        }
        
        // Try by text mapping
        $months = [
            'janv' => 1, 'fev' => 2, 'fév' => 2, 'mar' => 3, 'avr' => 4,
            'mai' => 5, 'juin' => 6, 'juil' => 7, 'aou' => 8, 'aoû' => 8,
            'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12, 'déc' => 12
        ];
        
        $lower = mb_strtolower($monthName, 'UTF-8');
        foreach ($months as $key => $num) {
            if (str_contains($lower, $key)) {
                return $num;
            }
        }
        
        return null;
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
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $fi = new \FilesystemIterator($file->getPathname(), \FilesystemIterator::SKIP_DOTS);
                if (!iterator_count($fi)) {
                    $fullFilePath = str_replace('\\', '/', $file->getPathname());
                    $relativePath = ltrim(str_replace($path, '', $fullFilePath), '/');
                    $folderParts = explode('/', $relativePath);
                    $folderName = count($folderParts) > 0 ? end($folderParts) : 'Racine';
                    $monthFolder = count($folderParts) > 1 ? $folderParts[count($folderParts) - 2] : null;

                    $files[] = [
                        'name' => 'empty.txt',
                        'path' => $relativePath . '/empty.txt',
                        'folder' => $folderName,
                        'month_folder' => $monthFolder,
                        'extension' => 'txt',
                        'size' => 0,
                        'exists' => false,
                        'is_empty_dir' => true,
                        'detected_date' => null,
                        'detected_year' => null,
                        'detected_month' => null,
                    ];
                }
            } elseif ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $allowedExtensions)) {
                    $fullFilePath = str_replace('\\', '/', $file->getPathname());
                    $relativePath = ltrim(str_replace($path, '', $fullFilePath), '/');
                    $folderName = $this->extractFolderName($relativePath);
                    $monthFolder = $this->extractMonthName($relativePath);
                    $detected = $this->detectDate($fullFilePath, $file->getFilename(), $extension);
                    $files[] = [
                        'name' => $file->getFilename(),
                        'path' => $relativePath,
                        'folder' => $folderName,
                        'month_folder' => $monthFolder,
                        'extension' => $extension,
                        'size' => $file->getSize(),
                        'exists' => false,
                        'is_empty_dir' => false,
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

    private function getOrCreateDossier(int $moisId, string $folderName): Dossier
    {
        $dossier = Dossier::where('mois_id', $moisId)->where('nom', $folderName)->first();
        if (!$dossier) {
            $moisModel = DossierMois::with('annee')->find($moisId);
            $anneeClean = $moisModel->annee->annee;
            $moisClean = $moisModel->mois;
            
            // Generate clean name without accents
            $nomClean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_',
                iconv('UTF-8', 'ASCII//TRANSLIT', $folderName)
            ));

            $codeUnique = "DOSSIER_{$anneeClean}_{$moisClean}_{$nomClean}";
            $codeCounter = 1;
            $finalCode = $codeUnique;
            while (Dossier::where('code', $finalCode)->exists()) {
                $finalCode = $codeUnique . '_' . $codeCounter;
                $codeCounter++;
            }

            $dossier = Dossier::create([
                'mois_id' => $moisId,
                'nom' => $folderName,
                'code' => $finalCode,
                'active' => true,
                'couleur' => '#1976D2',
                'description' => 'Dossier généré automatiquement par l\'import'
            ]);
        }
        
        $dossier->load('mois.annee');
        return $dossier;
    }

    public function importFiles(Request $request)
    {
        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json(['error' => 'Accès non autorisé'], 403);
        }

        $files = $request->input('files', []);
        $basePath = str_replace('\\', '/', rtrim($request->input('base_path'), '/'));
        $fallbackDate = $request->input('date_document') ?: date('Y-m-d');
        
        $globalMoisId = $request->input('mois_id');
        $anneeId = $request->input('annee_id');

        if (empty($files)) {
            return response()->json(['error' => 'Aucun fichier à importer'], 400);
        }

        if (!$globalMoisId && !$anneeId) {
            return response()->json(['error' => 'Veuillez sélectionner un mois ou une année cible'], 400);
        }

        $imported = 0;
        $errors = 0;
        $duplicates = 0;
        $results = [];

        foreach ($files as $file) {
            $moisId = $globalMoisId;
            if ($anneeId) {
                $monthRaw = $this->extractMonthName($file['path'] ?? '');
                $monthNum = $this->parseMonthNumber($monthRaw);
                if ($monthNum) {
                    $moisModel = DossierMois::where('annee_id', $anneeId)->where('mois', $monthNum)->first();
                    if ($moisModel) {
                        $moisId = $moisModel->id;
                    } else {
                        $results[] = ['file' => $file['name'], 'success' => false, 'error' => "Mois introuvable en base pour le dossier '{$monthRaw}'"];
                        $errors++;
                        continue;
                    }
                } else {
                    $results[] = ['file' => $file['name'], 'success' => false, 'error' => "Impossible de détecter le mois dans le chemin"];
                    $errors++;
                    continue;
                }
            }

            // Check if year is closed
            $moisModel = DossierMois::with('annee')->find($moisId);
            if ($moisModel && $moisModel->annee && $moisModel->annee->cloturee) {
                $results[] = ['file' => $file['name'], 'success' => false, 'error' => "L'année cible est clôturée"];
                $errors++;
                continue;
            }

            $folderName = $file['folder'] ?? 'Racine';
            $dossier = $this->getOrCreateDossier($moisId, $folderName);

            if (!empty($file['is_empty_dir'])) {
                $results[] = ['file' => 'Dossier vide (' . $folderName . ')', 'success' => true];
                $imported++;
                continue;
            }

            $result = $this->importSingleFile($file, $basePath, $dossier, $fallbackDate);
            $results[] = $result;
            
            if ($result['success']) {
                $imported++;
            } elseif (isset($result['is_duplicate']) && $result['is_duplicate']) {
                $duplicates++;
            } else {
                $errors++;
            }
        }

        return response()->json([
            'imported' => $imported,
            'errors' => $errors,
            'duplicates' => $duplicates,
            'results' => $results
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

        if ($this->isFileDuplicate($file['name'], $file['size'], $dossier->id)) {
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

    public function uploadMass(Request $request)
    {
        $user = Auth::user();
        if (!$user->isAdmin()) {
            return response()->json(['error' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'file' => 'required|file',
            'folder' => 'required|string',
            'mois_id' => 'required_without:annee_id|nullable|exists:dossier_mois,id',
            'annee_id' => 'required_without:mois_id|nullable|exists:dossier_annees,id',
            'relative_path' => 'nullable|string',
            'detected_date' => 'nullable|date',
            'fallback_date' => 'nullable|date'
        ]);

        $folderName = $request->input('folder') ?? 'Racine';
        
        if ($request->filled('annee_id')) {
            $monthRaw = $this->extractMonthName($request->input('relative_path') ?? '');
            $monthNum = $this->parseMonthNumber($monthRaw);
            if ($monthNum) {
                $moisModel = DossierMois::where('annee_id', $request->input('annee_id'))
                    ->where('mois', $monthNum)
                    ->first();
                if ($moisModel) {
                    $moisId = $moisModel->id;
                } else {
                    return response()->json(['error' => "Mois introuvable en base pour le dossier '{$monthRaw}'"], 400);
                }
            } else {
                 return response()->json(['error' => "Impossible de détecter le mois dans le chemin : " . ($request->input('relative_path') ?? 'aucun') . " (Attendu ex: '01_Janvier')"], 400);
            }
        } else {
            $moisId = $request->input('mois_id');
        }

        // Handle empty directory creation
        if ($request->filled('is_empty_dir')) {
            $this->getOrCreateDossier($moisId, $folderName);
            return response()->json(['success' => true]);
        }

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        $dateDocument = $request->input('detected_date');
        if (empty($dateDocument) || $dateDocument === 'null') {
            $detected = $this->detectDate($file->getPathname(), $originalName, $extension);
            $dateDocument = $detected['date'] ?? null;
            
            // Si pas de date trouvée dans le fichier, on prend la date OS (ordi local)
            if (empty($dateDocument)) {
                $osDate = $request->input('os_date');
                if (!empty($osDate) && $osDate !== 'null') {
                    $dateDocument = $osDate;
                }
            }
            
            // Final fallback
            if (empty($dateDocument)) {
                $dateDocument = $request->input('fallback_date') ?: now()->toDateString();
            }
        }

        // Créer ou récupérer le dossier
        $dossier = $this->getOrCreateDossier($moisId, $folderName);
        
        if ($dossier->mois->annee->cloturee) {
            return response()->json(['error' => "Année clôturée"], 400);
        }

        if ($this->isFileDuplicate($originalName, $size, $dossier->id)) {
            return response()->json(['error' => 'Doublon', 'is_duplicate' => true], 400);
        }

        try {
            $storagePath = 'archives/' . $dossier->mois->annee->annee . '/' . $dossier->mois->mois . '/' . $dossier->nom;
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            
            $path = $file->storeAs($storagePath, $fileName, 'public');

            $reference = $this->generateUniqueReference($originalName);

            $archive = Archive::create([
                'titre' => pathinfo($originalName, PATHINFO_FILENAME),
                'reference' => $reference,
                'dossier_id' => $dossier->id,
                'type_document' => $extension,
                'fichier_path' => $path,
                'fichier_nom_original' => $originalName,
                'fichier_taille' => $size,
                'mime_type' => $mimeType,
                'date_document' => $dateDocument,
                'created_by' => $user->id,
                'validation_status' => Archive::STATUS_PENDING,
            ]);
            
            \App\Models\ActivityLog::log('archive_created', "A importé le document {$archive->reference} : {$archive->titre} via le scan de masse");

            return response()->json([
                'success' => true,
                'file' => $originalName,
                'reference' => $reference,
                'date_document' => $dateDocument,
                'dossier' => $dossier->nom,
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json(['error' => 'Doublon (référence existante)', 'is_duplicate' => true], 400);
            }
            \Illuminate\Support\Facades\Log::error('UploadMass DB Error: ' . $e->getMessage(), ['file' => $originalName]);
            return response()->json(['error' => 'Erreur BDD: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::error('UploadMass Exception: ' . $e->getMessage(), ['file' => $originalName, 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
