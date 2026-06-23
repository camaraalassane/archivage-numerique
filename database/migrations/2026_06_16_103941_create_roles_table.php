<?php
// database/migrations/2026_06_16_103941_create_roles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Vérifier si la table roles n'existe pas déjà
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('code')->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Insérer les rôles par défaut
            $roles = [
                ['name' => 'Archiviste', 'code' => 'archiviste', 'description' => 'Peut créer et gérer ses propres archives', 'is_active' => true],
                ['name' => 'Gestionnaire', 'code' => 'gestionnaire', 'description' => 'Peut gérer toutes les archives et valider', 'is_active' => true],
                ['name' => 'Administrateur', 'code' => 'admin', 'description' => 'Accès complet à toutes les fonctionnalités', 'is_active' => true],
                ['name' => 'Division', 'code' => 'division', 'description' => 'Peut consulter les archives validées uniquement', 'is_active' => true],
            ];

            foreach ($roles as $role) {
                if (!DB::table('roles')->where('code', $role['code'])->exists()) {
                    DB::table('roles')->insert($role);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
