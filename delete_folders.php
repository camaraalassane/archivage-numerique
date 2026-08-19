<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$annee = App\Models\DossierAnnee::where('annee', '2026')->first();
if (!$annee) {
    echo "Annee 2026 not found.\n";
    exit;
}

$mois = App\Models\DossierMois::where('annee_id', $annee->id)->whereIn('nom_mois', ['Juillet', 'Août'])->get();

$totalArchives = 0;
$totalDossiers = 0;

foreach($mois as $m) {
    echo "Cleaning Mois: " . $m->nom_mois . "\n";
    $dossiers = App\Models\Dossier::where('mois_id', $m->id)->get();
    
    foreach($dossiers as $d) {
        $archives = App\Models\Archive::where('dossier_id', $d->id)->get();
        foreach($archives as $a) {
            // Delete physical file
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($a->fichier_path)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($a->fichier_path);
            }
            $a->delete();
            $totalArchives++;
        }
        $d->delete();
        $totalDossiers++;
    }
}

echo "Deleted $totalArchives archives and $totalDossiers dossiers.\n";
