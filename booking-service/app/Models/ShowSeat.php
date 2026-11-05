<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShowSeat extends Model
{
    protected $fillable = [
        'show_id',
        'seat_id',
        'seat_number',
        'ticket_price',
        'status',
        'locked_by',
        'lock_token',
        'lock_expires_at',
        'booking_id',
    ];

    protected function casts(): array
    {
        return [
            'ticket_price' => 'decimal:2',
            'lock_expires_at' => 'datetime',
        ];
    }
}