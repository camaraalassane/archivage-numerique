<?php
// app/Models/DossierMois.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class DossierMois extends Model
{
    protected $fillable = ['annee_id', 'mois', 'nom_mois', 'code', 'description', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'mois' => 'integer',
    ];

    public function annee(): BelongsTo
    {
        return $this->belongsTo(DossierAnnee::class, 'annee_id');
    }

    public function dossiers(): HasMany
    {
        return $this->hasMany(Dossier::class, 'mois_id');
    }

    public function archives(): HasManyThrough
    {
        return $this->hasManyThrough(Archive::class, Dossier::class, 'mois_id', 'dossier_id');
    }
}
