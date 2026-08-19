<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$controller = new App\Http\Controllers\ImportController();
$detected = $controller->detectDate('', 'NDS N°00001043 DCA-SD-MHCCA DU 01 10 2025.pdf', 'pdf');
var_dump($detected);
