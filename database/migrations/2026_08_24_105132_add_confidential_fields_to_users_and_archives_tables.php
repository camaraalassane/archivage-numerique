<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('peut_archiver_confidentiel')->default(false)->after('role');
            $table->boolean('peut_valider_confidentiel')->default(false)->after('peut_archiver_confidentiel');
            $table->boolean('peut_consulter_confidentiel')->default(false)->after('peut_valider_confidentiel');
            $table->string('mot_de_passe_confidentiel')->nullable()->after('peut_consulter_confidentiel');
        });

        Schema::table('archives', function (Blueprint $table) {
            // Default 2 means "Ordinary", 1 means "Confidential"
            $table->tinyInteger('type_document_confidentiel')->default(2)->after('type_document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('archives', function (Blueprint $table) {
            $table->dropColumn('type_document_confidentiel');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'peut_archiver_confidentiel',
                'peut_valider_confidentiel',
                'peut_consulter_confidentiel',
                'mot_de_passe_confidentiel'
            ]);
        });
    }
};
