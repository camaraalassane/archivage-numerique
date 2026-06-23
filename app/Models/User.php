<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // Constantes pour les rôles
    const ROLE_ARCHIVISTE = 1;
    const ROLE_GESTIONNAIRE = 2;
    const ROLE_ADMIN = 3;
    const ROLE_DIVISION = 4;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => 'integer',
        ];
    }

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class, 'created_by');
    }

    public function archivesValidees(): HasMany
    {
        return $this->hasMany(Archive::class, 'validated_by');
    }

    // === VÉRIFICATION DES RÔLES ===
    public function isArchiviste(): bool
    {
        return $this->role === self::ROLE_ARCHIVISTE;
    }

    public function isGestionnaire(): bool
    {
        return $this->role === self::ROLE_GESTIONNAIRE;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isDivision(): bool
    {
        return $this->role === self::ROLE_DIVISION;
    }

    public function hasRole(int $role): bool
    {
        return $this->role === $role;
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles);
    }

    // === PERMISSIONS ===
    public function canValidateArchives(): bool
    {
        return $this->isAdmin() || $this->isGestionnaire();
    }

    public function canManageAll(): bool
    {
        return $this->isAdmin() || $this->isGestionnaire();
    }

    public function canManageUsers(): bool
    {
        return $this->isAdmin();
    }

    public function canViewAllArchives(): bool
    {
        return $this->isAdmin() || $this->isGestionnaire();
    }

    public function canExport(): bool
    {
        return $this->isAdmin() || $this->isGestionnaire();
    }

    public function canManageYears(): bool
    {
        return $this->isAdmin() || $this->isGestionnaire();
    }

    public function canManageMonths(): bool
    {
        return $this->isAdmin() || $this->isGestionnaire();
    }

    public function canManageDossiers(): bool
    {
        return $this->isAdmin() || $this->isGestionnaire() || $this->isArchiviste();
    }

    public function canDownloadArchive(Archive $archive): bool
    {
        // Admin et Gestionnaire peuvent toujours télécharger
        if ($this->isAdmin() || $this->isGestionnaire()) {
            return true;
        }

        // Archiviste peut télécharger ses propres fichiers validés
        if ($this->isArchiviste()) {
            return $archive->created_by === $this->id && $archive->isValidated();
        }

        // Division peut seulement télécharger les fichiers validés
        if ($this->isDivision()) {
            return $archive->isValidated();
        }

        return false;
    }

    public function canViewArchive(Archive $archive): bool
    {
        // Admin et Gestionnaire peuvent tout voir
        if ($this->isAdmin() || $this->isGestionnaire()) {
            return true;
        }

        // Archiviste peut voir ses propres fichiers
        if ($this->isArchiviste()) {
            return $archive->created_by === $this->id;
        }

        // Division peut voir les fichiers validés
        if ($this->isDivision()) {
            return $archive->isValidated();
        }

        return false;
    }

    // === ACCESSEURS ===
    public function getRoleNameAttribute(): string
    {
        return match($this->role) {
            self::ROLE_ARCHIVISTE => 'Archiviste',
            self::ROLE_GESTIONNAIRE => 'Gestionnaire',
            self::ROLE_ADMIN => 'Administrateur',
            self::ROLE_DIVISION => 'Division',
            default => 'Inconnu',
        };
    }

    public function getRoleColorAttribute(): string
    {
        return match($this->role) {
            self::ROLE_ARCHIVISTE => 'blue',
            self::ROLE_GESTIONNAIRE => 'green',
            self::ROLE_ADMIN => 'red',
            self::ROLE_DIVISION => 'orange',
            default => 'grey',
        };
    }

    public function getRoleIconAttribute(): string
    {
        return match($this->role) {
            self::ROLE_ARCHIVISTE => 'mdi-folder-account',
            self::ROLE_GESTIONNAIRE => 'mdi-account-cog',
            self::ROLE_ADMIN => 'mdi-shield-account',
            self::ROLE_DIVISION => 'mdi-account-eye',
            default => 'mdi-account',
        };
    }

    public function getRoleBadgeAttribute(): string
    {
        return match($this->role) {
            self::ROLE_ARCHIVISTE => 'bg-blue-100 text-blue-800',
            self::ROLE_GESTIONNAIRE => 'bg-green-100 text-green-800',
            self::ROLE_ADMIN => 'bg-red-100 text-red-800',
            self::ROLE_DIVISION => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }
}
