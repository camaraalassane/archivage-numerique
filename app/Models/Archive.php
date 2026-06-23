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
}
