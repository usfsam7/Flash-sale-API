<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'idempotency_key',
        'order_id',
        'hold_id',
        'status',
        'amount',
        'metadata',
        'applied',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'applied' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }
}
