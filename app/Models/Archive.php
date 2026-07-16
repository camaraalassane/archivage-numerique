<?php
// app/Models/Archive.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Archive extends Model
{
    use SoftDeletes;

    // Constantes pour les statuts de validation
    const STATUS_PENDING = 'pending';
    const STATUS_VALIDATED = 'validated';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'dossier_id',
        'titre',
        'reference',
        'description',
        'type_document',
        'fichier_path',
        'fichier_nom_original',
        'fichier_taille',
        'mime_type',
        'date_document',
        'metadata',
        'mots_cles',
        'version',
        'est_favori',
        'created_by',
        'validation_status',
        'validated_by',
        'validated_at',
        'validation_comment',
    ];

    protected $casts = [
        'metadata' => 'array',
        'est_favori' => 'boolean',
        'version' => 'integer',
        'date_document' => 'date',
        'fichier_taille' => 'integer',
        'deleted_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    // === RELATIONS ===
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    // === VÉRIFICATIONS DE STATUT ===
    public function isPending(): bool
    {
        return $this->validation_status === self::STATUS_PENDING;
    }

    public function isValidated(): bool
    {
        return $this->validation_status === self::STATUS_VALIDATED;
    }

    public function isRejected(): bool
    {
        return $this->validation_status === self::STATUS_REJECTED;
    }

    public function canBeValidated(): bool
    {
        return $this->isPending();
    }

    // ==============================================
    // === PERMISSIONS DE VISUALISATION ET TÉLÉCHARGEMENT ===
    // ==============================================

    /**
     * Vérifie si un utilisateur peut visualiser cette archive
     */
    public function canBeViewedBy(User $user): bool
    {
        // Admin et Gestionnaire peuvent tout voir
        if ($user->isAdmin() || $user->isGestionnaire()) {
            return true;
        }

        // Archiviste peut voir ses propres fichiers
        if ($user->isArchiviste()) {
            return $this->created_by === $user->id;
        }

        // Division peut voir les fichiers validés
        if ($user->isDivision()) {
            return $this->isValidated();
        }

        return false;
    }

    /**
     * Vérifie si un utilisateur peut télécharger cette archive
     */
    public function canBeDownloadedBy(User $user): bool
    {
        // Admin et Gestionnaire peuvent toujours télécharger
        if ($user->isAdmin() || $user->isGestionnaire()) {
            return true;
        }

        // Archiviste peut télécharger ses propres fichiers validés
        if ($user->isArchiviste()) {
            return $this->created_by === $user->id && $this->isValidated();
        }

        // Division peut seulement télécharger les fichiers validés
        if ($user->isDivision()) {
            return $this->isValidated();
        }

        return false;
    }

    /**
     * Vérifier l'existence d'un doublon
     */
    public static function isDuplicate($reference, $dossierId, $fileName = null)
    {
        $query = self::where('reference', $reference)
            ->where('dossier_id', $dossierId);

        if ($fileName) {
            $query->orWhere(function($q) use ($fileName, $dossierId) {
                $q->where('fichier_nom_original', $fileName)
                  ->where('dossier_id', $dossierId);
            });
        }

        return $query->exists();
    }

    /**
     * Obtenir les doublons potentiels
     */
    public static function getPotentialDuplicates($searchTerm, $dossierId = null)
    {
        $query = self::where(function($q) use ($searchTerm) {
            $q->where('reference', 'LIKE', '%' . $searchTerm . '%')
              ->orWhere('titre', 'LIKE', '%' . $searchTerm . '%')
              ->orWhere('fichier_nom_original', 'LIKE', '%' . $searchTerm . '%');
        });

        if ($dossierId) {
            $query->where('dossier_id', $dossierId);
        }

        return $query->with(['dossier.mois.annee', 'createur'])->get();
    }

    // === ACCESSEURS ===
    public function getStatusLabelAttribute(): string
    {
        return match($this->validation_status) {
            self::STATUS_PENDING => 'En attente',
            self::STATUS_VALIDATED => 'Validé',
            self::STATUS_REJECTED => 'Rejeté',
            default => 'Inconnu',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->validation_status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_VALIDATED => 'success',
            self::STATUS_REJECTED => 'error',
            default => 'grey',
        };
    }

    public function getStatusIconAttribute(): string
    {
        return match($this->validation_status) {
            self::STATUS_PENDING => 'mdi-clock-outline',
            self::STATUS_VALIDATED => 'mdi-check-circle',
            self::STATUS_REJECTED => 'mdi-close-circle',
            default => 'mdi-help-circle',
        };
    }

    public function getAnneeAttribute(): ?int
    {
        try {
            if ($this->dossier && $this->dossier->mois && $this->dossier->mois->annee) {
                return $this->dossier->mois->annee->annee;
            }
        } catch (\Exception $e) {
            // Ignorer l'erreur
        }
        return null;
    }

    public function getMoisAttribute(): ?int
    {
        try {
            if ($this->dossier && $this->dossier->mois) {
                return $this->dossier->mois->mois;
            }
        } catch (\Exception $e) {
            // Ignorer l'erreur
        }
        return null;
    }

    public function getNomMoisAttribute(): ?string
    {
        try {
            if ($this->dossier && $this->dossier->mois) {
                return $this->dossier->mois->nom_mois;
            }
        } catch (\Exception $e) {
            // Ignorer l'erreur
        }
        return null;
    }

    public function getDossierNomAttribute(): ?string
    {
        try {
            if ($this->dossier) {
                return $this->dossier->nom;
            }
        } catch (\Exception $e) {
            // Ignorer l'erreur
        }
        return null;
    }

    public function getCheminCompletAttribute(): string
    {
        return "{$this->annee} / {$this->nom_mois} / {$this->dossier_nom}";
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->fichier_taille;
        if (!$bytes) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
