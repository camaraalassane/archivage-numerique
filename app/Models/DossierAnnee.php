<?php
// app/Models/DossierAnnee.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class DossierAnnee extends Model
{
    protected $fillable = [
        'annee',
        'code',
        'description',
        'active',
        'cloturee', // AJOUTER CETTE LIGNE
    ];

    protected $casts = [
        'active' => 'boolean',
        'cloturee' => 'boolean', // AJOUTER CETTE LIGNE
        'annee' => 'integer',
    ];

    public function mois(): HasMany
    {
        return $this->hasMany(DossierMois::class, 'annee_id');
    }

    public function dossiers(): HasManyThrough
    {
        return $this->hasManyThrough(Dossier::class, DossierMois::class, 'annee_id', 'mois_id');
    }

    public function archives(): HasManyThrough
    {
        return $this->hasManyThrough(Archive::class, Dossier::class, 'mois_id', 'dossier_id');
    }
}
