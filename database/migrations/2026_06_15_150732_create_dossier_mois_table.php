<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_mois', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_id')->constrained('dossier_annees')->onDelete('cascade');
            $table->integer('mois');
            $table->string('nom_mois', 50);
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['annee_id', 'mois']);
            $table->index('mois');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_mois');
    }
};
