<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movie extends Model
{
    protected $fillable = [
        'title',
        'description',
        'language',
        'genre',
        'duration_minutes',
        'release_date',
        'certificate',
        'poster_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class);
    }
}