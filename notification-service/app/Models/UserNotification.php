<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $fillable = [
        'event_id',
        'event_type',
        'topic',
        'user_id',
        'title',
        'message',
        'data',
        'occurred_at',
        'read_at',
        'delivery_status',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
