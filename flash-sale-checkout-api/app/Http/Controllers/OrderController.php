<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Payment;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'hold_id' => ['required', 'integer', 'exists:holds,id'],
        ]);

        // $user = $request->user();
        // if (! $user) {
        //     return response()->json(['message' => 'Unauthenticated.'], 401);
        // }

        return DB::transaction(function () use ($data) {
            // Lock the hold row so concurrent requests can't both consume it.
            $hold = Hold::where('id', $data['hold_id'])->lockForUpdate()->first();

            if (! $hold) {
                return response()->json(['message' => 'Hold not found.'], 404);
            }

            // if ($hold->user_id !== $user->id) {
            //     return response()->json(['message' => 'Forbidden.'], 403);
            // }

            if ($hold->released || ($hold->expires_at && $hold->expires_at->isPast())) {
                return response()->json(['message' => 'Hold expired or released.'], 422);
            }

            if (! empty($hold->used)) {
                return response()->json(['message' => 'Hold already used.'], 422);
            }

            $product = $hold->product;
            if (! $product) {
                return response()->json(['message' => 'Hold product missing.'], 422);
            }

            $total = ($product->price * $hold->qty);

            $order = Order::create([
                'hold_id' => $hold->id,
                'product_id' => $product->id,
                'quantity' => $hold->qty,
                'total_amount' => $total,
                'status' => 'pre_payment',
            ]);

            // mark hold used so it can't be reused
            $hold->used = true;
            $hold->save();

             // If a successful payment already exists for this hold, apply it immediately
            $pending = Payment::where('hold_id', $hold->id)
                        ->where('status', 'success')
                        ->where('applied', false)
                        ->lockForUpdate()
                        ->first();

            if ($pending) {
                // attempt to atomically decrement stock; if fails, cancel order & release hold
                $qty = $order->quantity;
                $updated = DB::table('products')
                    ->where('id', $order->product_id)
                    ->where('stock', '>=', $qty)
                    ->decrement('stock', $qty);

                if (! $updated) {
                    $order->status = 'cancelled';
                    $order->save();

                    $hold->released = true;
                    $hold->save();

                    $pending->order_id = $order->id;
                    $pending->applied = true;
                    $pending->save();

                    return response()->json(['message' => 'Insufficient stock, order cancelled'], 422);
                }

                $order->status = 'paid';
                $order->save();

                $pending->order_id = $order->id;
                $pending->applied = true;
                $pending->save();
            }

            return response()->json($order, 201);
        });
    }
}
