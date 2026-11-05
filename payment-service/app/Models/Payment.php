<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'user_id',
        'payment_reference',
        'idempotency_key',
        'amount',
        'currency',
        'payment_method',
        'gateway_name',
        'gateway_transaction_id',
        'status',
        'paid_at',
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}