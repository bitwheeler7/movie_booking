<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    protected $fillable = [
        'event_id',
        'topic',
        'payload',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'published_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'locked_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}