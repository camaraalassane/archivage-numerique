<?php
// database/migrations/2026_06_22_122728_add_validation_fields_to_archives_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('archives')) {
            return;
        }

        Schema::table('archives', function (Blueprint $table) {
            // Ajouter validation_status si elle n'existe pas
            if (!Schema::hasColumn('archives', 'validation_status')) {
                $table->enum('validation_status', ['pending', 'validated', 'rejected'])
                    ->default('pending')
                    ->after('est_favori');
            }

            // Ajouter validated_by si elle n'existe pas
            if (!Schema::hasColumn('archives', 'validated_by')) {
                $table->foreignId('validated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('validation_status');
            }

            // Ajouter validated_at si elle n'existe pas
            if (!Schema::hasColumn('archives', 'validated_at')) {
                $table->timestamp('validated_at')->nullable()->after('validated_by');
            }

            // Ajouter validation_comment si elle n'existe pas
            if (!Schema::hasColumn('archives', 'validation_comment')) {
                $table->text('validation_comment')->nullable()->after('validated_at');
            }

            // Ajouter des index
            // Vérifier si l'index existe avant de l'ajouter
            if (!$this->hasIndex('archives', 'validation_status')) {
                $table->index('validation_status');
            }
            if (!$this->hasIndex('archives', 'validated_by')) {
                $table->index('validated_by');
            }
            if (!$this->hasIndex('archives', 'validated_at')) {
                $table->index('validated_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('archives')) {
            return;
        }

        Schema::table('archives', function (Blueprint $table) {
            // Supprimer les index (vérifier s'ils existent)
            if ($this->hasIndex('archives', 'validation_status')) {
                $table->dropIndex(['validation_status']);
            }
            if ($this->hasIndex('archives', 'validated_by')) {
                $table->dropIndex(['validated_by']);
            }
            if ($this->hasIndex('archives', 'validated_at')) {
                $table->dropIndex(['validated_at']);
            }

            // Supprimer les colonnes
            if (Schema::hasColumn('archives', 'validation_comment')) {
                $table->dropColumn('validation_comment');
            }

            if (Schema::hasColumn('archives', 'validated_at')) {
                $table->dropColumn('validated_at');
            }

            if (Schema::hasColumn('archives', 'validated_by')) {
                $table->dropForeign(['validated_by']);
                $table->dropColumn('validated_by');
            }

            if (Schema::hasColumn('archives', 'validation_status')) {
                $table->dropColumn('validation_status');
            }
        });
    }

    /**
     * Vérifie si un index existe sur une table
     */
    private function hasIndex(string $table, string $column): bool
    {
        try {
            // Récupérer les index de la table
            $indexes = Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes($table);

            // Vérifier si un index existe pour cette colonne
            foreach ($indexes as $index) {
                if (in_array($column, $index->getColumns())) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            // En cas d'erreur, on suppose que l'index n'existe pas
            return false;
        }
    }
};
