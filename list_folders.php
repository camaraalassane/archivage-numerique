<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$annee = App\Models\DossierAnnee::where('annee', '2026')->first();
$mois = App\Models\DossierMois::where('annee_id', $annee->id)->whereIn('nom_mois', ['Juillet', 'Août'])->with('dossiers.archives')->get();

foreach($mois as $m) {
    echo "Mois: " . $m->nom_mois . "\n";
    foreach($m->dossiers as $d) {
        echo "  Dossier: " . $d->nom . " (Archives: " . $d->archives->count() . ")\n";
    }
}
