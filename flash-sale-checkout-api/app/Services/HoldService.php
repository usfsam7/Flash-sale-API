<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HoldService
{
    public function createHold(int $productId, int $qty)
    {
        return DB::transaction(function () use ($productId, $qty) {

            // Lock product row to guarantee consistency
            $product = Product::where('id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            // Sum all active (non-expired, non-released) holds
            $activeQty = Hold::where('product_id', $productId)
                ->where('released', false)
                ->where('expires_at', '>', now())
                ->sum('qty');

            $available = $product->stock - $activeQty;

            if ($available < $qty) {
                throw new \Exception("Not enough stock");
            }

            $hold = Hold::create([
                'product_id' => $productId,
                'qty' => $qty,
                'expires_at' => Carbon::now()->addMinutes(2),
                'used' => false,
                'released' => false,
            ]);

            // Invalidate product availability cache
            $product->clearAvailabilityCache();

            return $hold;
        });
    }
}
