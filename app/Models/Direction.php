<?php
// app/Models/Direction.php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Direction extends Model
{
    protected $fillable = ['code', 'nom', 'description', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class);
    }
}
