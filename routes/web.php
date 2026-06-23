<?php
// routes/web.php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\DossierController;
use App\Http\Controllers\DossierAnneeController;
use App\Http\Controllers\DossierMoisController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\ArchivisteController;
use App\Http\Controllers\GestionnaireController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

// ============================================
// ROUTES ACCESSIBLES À TOUS LES UTILISATEURS CONNECTÉS
// ============================================
Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard - accessible à tous
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/search', [DashboardController::class, 'search'])->name('dashboard.search');

    // Profil - accessible à tous
    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'edit')->name('profile.edit');
        Route::patch('/profile', 'update')->name('profile.update');
        Route::delete('/profile', 'destroy')->name('profile.destroy');
    });

    // STATS : accessible à tous SAUF Division
    Route::middleware(['role:' . User::ROLE_ARCHIVISTE . ',' . User::ROLE_GESTIONNAIRE . ',' . User::ROLE_ADMIN])
        ->group(function () {
            Route::get('/stats', [StatsController::class, 'index'])->name('stats');
        });

    // === ROUTES DE VISUALISATION ET TÉLÉCHARGEMENT ACCESSIBLES À TOUS ===
    // Division peut aussi visualiser et télécharger
    Route::get('/archives/{archive}/view', [ArchiveController::class, 'viewFile'])->name('archives.view');
    Route::get('/archives/{archive}/download', [ArchiveController::class, 'download'])->name('archives.download');

    // Route AJAX pour charger les archives d'un dossier
    Route::get('/dossiers/{dossier}/archives', [DashboardController::class, 'getDossierArchives'])
        ->name('dossiers.archives');
});

// ============================================
// ROUTES ACCESSIBLES À ARCHIVISTE, GESTIONNAIRE ET ADMIN (RÔLE 1, 2, 3)
// ============================================
Route::middleware(['auth', 'verified', 'role:' . User::ROLE_ARCHIVISTE . ',' . User::ROLE_GESTIONNAIRE . ',' . User::ROLE_ADMIN])
    ->group(function () {

        // --- ARCHIVES (gestion) ---
        Route::get('/archives', [ArchiveController::class, 'index'])->name('archives.index');
        Route::post('/archives', [ArchiveController::class, 'store'])->name('archives.store');
        Route::post('/archives/bulk', [ArchiveController::class, 'storeMultiple'])->name('archives.store.multiple');
        Route::put('/archives/{archive}', [ArchiveController::class, 'update'])->name('archives.update');
        Route::post('/archives/{archive}/favorite', [ArchiveController::class, 'toggleFavorite'])->name('archives.favorite');
        Route::post('/archives/{archive}/version', [ArchiveController::class, 'newVersion'])->name('archives.new_version');

        // Suppression : uniquement Gestionnaire et Admin (géré dans le contrôleur)
        Route::delete('/archives/{archive}', [ArchiveController::class, 'destroy'])->name('archives.destroy');

        // Export : uniquement Gestionnaire et Admin (géré dans le contrôleur)
        Route::get('/archives/export', [ArchiveController::class, 'export'])->name('archives.export');
});

// ============================================
// ROUTES ACCESSIBLES À GESTIONNAIRE ET ADMIN (RÔLE 2, 3)
// ============================================
Route::middleware(['auth', 'verified', 'role:' . User::ROLE_GESTIONNAIRE . ',' . User::ROLE_ADMIN])
    ->group(function () {

        // --- VALIDATION DES ARCHIVES ---
        Route::post('/archives/{archive}/validate', [ArchiveController::class, 'validateArchive'])
            ->name('archives.validate');

        // --- GESTION DES DOSSIERS ---
        Route::resource('dossiers', DossierController::class)->except(['show']);
        Route::get('mois/{mois}/dossiers', [DossierController::class, 'byMois'])->name('dossiers.by_mois');
        Route::post('dossiers/{dossier}/toggle', [DossierController::class, 'toggle'])->name('dossiers.toggle');
        Route::post('dossiers/store-multiple', [DossierController::class, 'storeMultiple'])->name('dossiers.store.multiple');
        Route::get('/dossiers/list', [DossierController::class, 'list'])->name('dossiers.list');
});

// ============================================
// ROUTES ACCESSIBLES UNIQUEMENT À ADMIN (RÔLE 3)
// ============================================
Route::middleware(['auth', 'verified', 'role:' . User::ROLE_ADMIN])
    ->group(function () {

        // --- GESTION DES UTILISATEURS ---
        Route::resource('users', UserController::class)->except(['show', 'create', 'store']);
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // --- GESTION DES ANNÉES ---
        Route::resource('annees', DossierAnneeController::class)->except(['show']);
        Route::post('annees/{annee}/toggle', [DossierAnneeController::class, 'toggle'])->name('annees.toggle');
        Route::post('annees/{annee}/cloturer', [DossierAnneeController::class, 'cloturer'])->name('annees.cloturer');
        Route::post('annees/{annee}/rouvrir', [DossierAnneeController::class, 'rouvrir'])->name('annees.rouvrir');

        // --- GESTION DES MOIS ---
        Route::resource('mois', DossierMoisController::class)->except(['show']);
        Route::get('annees/{annee}/mois', [DossierMoisController::class, 'byAnnee'])->name('mois.by_annee');
        Route::post('mois/{mois}/toggle', [DossierMoisController::class, 'toggle'])->name('mois.toggle');

        // --- IMPORTATION DE FICHIERS ---
        Route::get('/import', [ImportController::class, 'index'])->name('import.index');
        Route::post('/import/scan', [ImportController::class, 'scanDirectory'])->name('import.scan');
        Route::post('/import/process', [ImportController::class, 'importFiles'])->name('import.process');
});

// ============================================
// ROUTES ACCESSIBLES UNIQUEMENT À ARCHIVISTE (RÔLE 1)
// ============================================
Route::middleware(['auth', 'verified', 'role:' . User::ROLE_ARCHIVISTE])
    ->group(function () {
        Route::get('/archiviste/pending-rejected', [ArchivisteController::class, 'pendingRejected'])
            ->name('archiviste.pending-rejected');

        Route::put('/archiviste/{archive}', [ArchivisteController::class, 'update'])
            ->name('archiviste.update');

        Route::delete('/archiviste/{archive}', [ArchivisteController::class, 'destroy'])
            ->name('archiviste.destroy');

        Route::get('/archiviste/{archive}/download', [ArchivisteController::class, 'download'])
            ->name('archiviste.download');

        Route::get('/archiviste/{archive}/view', [ArchivisteController::class, 'viewFile'])
            ->name('archiviste.view');
});

// ============================================
// ROUTES ACCESSIBLES UNIQUEMENT À GESTIONNAIRE (RÔLE 2)
// ============================================
Route::middleware(['auth', 'verified', 'role:' . User::ROLE_GESTIONNAIRE])
    ->group(function () {
        Route::get('/gestionnaire/pending-archives', [GestionnaireController::class, 'pendingArchives'])
            ->name('gestionnaire.pending-archives');

        Route::post('/gestionnaire/{archive}/validate', [GestionnaireController::class, 'validate'])
            ->name('gestionnaire.validate');

        Route::post('/gestionnaire/{archive}/reject', [GestionnaireController::class, 'reject'])
            ->name('gestionnaire.reject');

        Route::get('/gestionnaire/{archive}/view', [GestionnaireController::class, 'viewFile'])
            ->name('gestionnaire.view');

        Route::get('/gestionnaire/{archive}/download', [GestionnaireController::class, 'download'])
            ->name('gestionnaire.download');
});

require __DIR__.'/auth.php';
