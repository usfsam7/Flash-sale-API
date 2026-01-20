<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use App\Models\Payment;
use App\Services\Payments\PaymentStrategyRegistry;

class OrderController extends Controller
{
    private PaymentStrategyRegistry $strategies;

    public function __construct(PaymentStrategyRegistry $strategies)
    {
        $this->strategies = $strategies;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'hold_id' => ['required', 'integer', 'exists:holds,id'],
        ]);

        // Wrap the transactional work with a retry loop to handle deadlocks/serialization failures.
        $maxAttempts = 3;
        $backoffMs = 100; // initial backoff in ms
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return DB::transaction(function () use ($data) {
                    // Lock the hold row so concurrent requests can't both consume it.
                    $hold = Hold::where('id', $data['hold_id'])->lockForUpdate()->first();

                    if (! $hold) {
                        return response()->json(['message' => 'Hold not found.'], 404);
                    }

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
                        // delegate payment application to the strategy registry
                        $applied = $this->strategies->applyBest($pending, $order);

                        if (! $applied) {
                            $order->status = 'cancelled';
                            $order->save();

                            $hold->released = true;
                            $hold->save();

                            $pending->order_id = $order->id;
                            $pending->applied = true;
                            $pending->save();

                            return response()->json(['message' => 'Insufficient stock, order cancelled'], 422);
                        }
                    }

                    return response()->json($order, 201);
                });
            } catch (QueryException $e) {
                $sqlState = $e->errorInfo[0] ?? null;
                $driverCode = $e->errorInfo[1] ?? null;

                // Detect common deadlock/serialization codes
                $isDeadlock = in_array($sqlState, ['40001', '40P01']) || $driverCode == 1213;

                if ($isDeadlock && $attempt < $maxAttempts) {
                    Log::warning('OrderController transaction deadlock, retrying', [
                        'hold_id' => $data['hold_id'] ?? null,
                        'attempt' => $attempt,
                        'max' => $maxAttempts,
                        'sql_state' => $sqlState,
                        'driver_code' => $driverCode,
                        'message' => $e->getMessage(),
                    ]);

                    // exponential backoff
                    usleep($backoffMs * 1000 * $attempt);
                    continue;
                }

                // not a retryable exception or out of attempts: log and rethrow
                Log::error('OrderController transaction failed', [
                    'hold_id' => $data['hold_id'] ?? null,
                    'attempt' => $attempt,
                    'sql_state' => $sqlState,
                    'driver_code' => $driverCode,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }
}
