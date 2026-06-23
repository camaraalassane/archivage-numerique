<?php
// database/migrations/2026_06_16_103840_add_role_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Vérifier si la colonne role n'existe pas déjà
            if (!Schema::hasColumn('users', 'role')) {
                // 1 = Archiviste, 2 = Gestionnaire, 3 = Admin, 4 = Division
                $table->tinyInteger('role')->default(1)->after('password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
