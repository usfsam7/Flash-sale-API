<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Order;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class PaymentController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'idempotency_key' => 'required|string',
            'status' => 'required|in:success,failure',
            'order_id' => 'nullable|integer|exists:orders,id',
            'hold_id' => 'nullable|integer|exists:holds,id',
            'amount' => 'nullable|numeric',
            'metadata' => 'nullable|array',
        ]);

        try {
            return DB::transaction(function () use ($data) {
                // idempotent check
                $existing = Payment::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    // If we already associated this payment to an order return the order's final status
                    if ($existing->order_id && ($order = Order::find($existing->order_id))) {
                        return response()->json(['status' => $order->status], 200);
                    }

                    // otherwise return recorded payment status (success/failure/recorded)
                    return response()->json(['status' => $existing->status], 200);
                }

                $payment = Payment::create([
                    'idempotency_key' => $data['idempotency_key'],
                    'order_id' => $data['order_id'] ?? null,
                    'hold_id' => $data['hold_id'] ?? null,
                    'status' => $data['status'],
                    'amount' => $data['amount'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                    'applied' => false,
                ]);

                // try to find an order (by order_id or hold_id)
                $order = null;
                if (!empty($payment->order_id)) {
                    $order = Order::where('id', $payment->order_id)->lockForUpdate()->first();
                } elseif (!empty($payment->hold_id)) {
                    $order = Order::where('hold_id', $payment->hold_id)->lockForUpdate()->first();
                }

                if ($order) {
                    if ($order->status === 'paid' || $order->status === 'cancelled') {
                        $payment->order_id = $order->id;
                        $payment->applied = true;
                        $payment->save();

                        return response()->json(['status' => $order->status], 200);
                    }

                    if ($payment->status === 'success') {
                        $qty = $order->quantity;
                        $updated = DB::table('products')
                            ->where('id', $order->product_id)
                            ->where('stock', '>=', $qty)
                            ->decrement('stock', $qty);

                        if (! $updated) {
                            $order->status = 'cancelled';
                            $order->save();

                            if ($order->hold) {
                                $order->hold->released = true;
                                $order->hold->save();
                            }

                            $payment->order_id = $order->id;
                            $payment->applied = true;
                            $payment->save();

                            return response()->json(['status' => 'cancelled', 'reason' => 'insufficient_stock'], 422);
                        }

                        $order->status = 'paid';
                        $order->save();

                        $payment->order_id = $order->id;
                        $payment->applied = true;
                        $payment->save();

                        return response()->json(['status' => 'paid'], 200);
                    }

                    // failure — cancel order and release hold
                    $order->status = 'cancelled';
                    $order->save();

                    if ($order->hold) {
                        $order->hold->released = true;
                        $order->hold->save();
                    }

                    $payment->order_id = $order->id;
                    $payment->applied = true;
                    $payment->save();

                    return response()->json(['status' => 'cancelled'], 200);
                }

                // No order found:
                // If this is a failure webhook and it references a hold -> release the hold now
                if ($payment->status === 'failure' && $payment->hold_id) {
                    $hold = Hold::where('id', $payment->hold_id)->lockForUpdate()->first();
                    if ($hold && ! $hold->released) {
                        $hold->released = true;
                        $hold->save();
                    }
                }

                return response()->json(['status' => 'recorded'], 200);
            });
        } catch (QueryException $e) {
            $existing = Payment::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->order_id && ($order = Order::find($existing->order_id))) {
                    return response()->json(['status' => $order->status], 200);
                }
                return response()->json(['status' => $existing->status], 200);
            }
            throw $e;
        }
    }
}
