<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
     protected $fillable = [
        'hold_id',
        'product_id',
        'quantity',
        'total_amount',
        'status',
    ];

    // public function user()
    // {
    //     return $this->belongsTo(User::class);
    // }

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
