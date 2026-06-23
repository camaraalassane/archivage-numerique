<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mois_id')->constrained('dossier_mois')->onDelete('cascade');
            $table->string('nom', 191);
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->string('couleur', 20)->nullable();
            $table->integer('ordre')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['mois_id', 'nom']);
            $table->index('nom');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossiers');
    }
};
