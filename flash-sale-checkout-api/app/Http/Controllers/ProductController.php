<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Hold;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ProductController extends Controller
{
    /**
     * GET /api/products/{id}
     */
    public function show($id)
    {
        $product = Product::findOrFail($id);

        // Cache the computed 'available' for a very short TTL to reduce DB pressure under bursts.
        // We compute: available = stock - SUM(active holds qty)
        $cacheKey = $product->availabilityCacheKey();

        $available = Cache::remember($cacheKey, 3, function () use ($product) {
            // Sum active holds (released = false, expires_at in future)
            $activeQty = Hold::where('product_id', $product->id)
                ->where('released', false)
                ->where('expires_at', '>', Carbon::now())
                ->sum('qty');

            $computed = (int) $product->stock - (int) $activeQty;
            return max(0, $computed);
        });

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'stock' => $product->stock,
            'available' => $available,
        ]);
    }
}
