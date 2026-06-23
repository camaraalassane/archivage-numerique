<?php
// app/Models/Dossier.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dossier extends Model
{
    protected $fillable = ['mois_id', 'nom', 'code', 'description', 'couleur', 'ordre', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'ordre' => 'integer',
    ];

    public function mois(): BelongsTo
    {
        return $this->belongsTo(DossierMois::class, 'mois_id');
    }

    public function annee(): BelongsTo
    {
        return $this->mois->annee();
    }

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class);
    }

    // Accesseur pour le chemin complet
    public function getCheminCompletAttribute(): string
    {
        return "{$this->mois->annee->annee}/{$this->mois->nom_mois}/{$this->nom}";
    }
}
