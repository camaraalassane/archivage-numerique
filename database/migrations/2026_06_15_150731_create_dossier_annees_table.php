<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_annees', function (Blueprint $table) {
            $table->id();
            $table->integer('annee')->unique();
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index('annee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_annees');
    }
};
