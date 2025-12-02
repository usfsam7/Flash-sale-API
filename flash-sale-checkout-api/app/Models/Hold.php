<?php

namespace App\Models;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $fillable = ['product_id', 'qty', 'expires_at', 'used', 'released'];

    protected $casts = [
        'expires_at' => 'datetime',
        'released' => 'boolean',
        'used' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('released', false)
            ->where('expires_at', '>', Carbon::now());
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
