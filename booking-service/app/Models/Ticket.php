<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $fillable = [
        'ticket_number',
        'booking_id',
        'user_id',
        'show_id',
        'seats',
        'status',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'seats' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
