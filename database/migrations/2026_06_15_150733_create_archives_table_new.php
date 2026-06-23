<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_id')->constrained('dossiers')->onDelete('cascade');

            $table->string('titre');
            $table->string('reference')->unique();
            $table->text('description')->nullable();
            $table->string('type_document');
            $table->string('fichier_path');
            $table->string('fichier_nom_original');
            $table->bigInteger('fichier_taille');
            $table->string('mime_type');
            $table->date('date_document');
            $table->json('metadata')->nullable();
            $table->string('mots_cles', 255)->nullable();
            $table->integer('version')->default(1);
            $table->boolean('est_favori')->default(false);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            $table->index('titre');
            $table->index('reference');
            $table->index('date_document');
            $table->index('type_document');
            $table->index('mots_cles');
            $table->index(['dossier_id', 'date_document']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archives');
    }
};
