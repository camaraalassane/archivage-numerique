<?php
// app/Models/Service.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = ['code', 'nom', 'description', 'direction_id', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];
protected $withCount = ['archives'];
    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class);
    }
}
