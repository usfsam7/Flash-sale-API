<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Product extends Model
{
    protected $fillable = ['name', 'price', 'stock'];
    /**
     * Cache key for available stock
     */
    public function availabilityCacheKey(): string
    {
        return "product:{$this->id}:available";
    }

    /**
     * Clear cached available value (call when product stock changes).
     */
    public function clearAvailabilityCache(): void
    {
        Cache::forget($this->availabilityCacheKey());
    }

    /**
     * Boot to clear cache on save/delete (so stock updates are reflected immediately).
     */
    protected static function booted()
    {
        static::saved(function (Product $p) {
            $p->clearAvailabilityCache();
        });

        static::deleted(function (Product $p) {
            $p->clearAvailabilityCache();
        });
    }

}

