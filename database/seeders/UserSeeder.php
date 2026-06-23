<?php
// database/seeders/UserSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Utilisateur 1: Administrateur
        User::create([
            'name' => 'Admin Système',
            'email' => 'admin@archivage.com',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);

        // Utilisateur 2: Archiviste
        User::create([
            'name' => 'Jean Dupont',
            'email' => 'archiviste@archivage.com',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);

        // Message de confirmation
        $this->command->info('✅ 2 utilisateurs créés avec succès !');
        $this->command->info('📧 admin@archivage.com / password123');
        $this->command->info('📧 archiviste@archivage.com / password123');
    }
}
