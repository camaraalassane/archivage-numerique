<?php
// database/migrations/[timestamp]_add_cloturee_to_dossier_annees_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_annees', function (Blueprint $table) {
            $table->boolean('cloturee')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_annees', function (Blueprint $table) {
            $table->dropColumn('cloturee');
        });
    }
};
