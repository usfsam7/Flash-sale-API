<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class DefaultPaymentStrategy implements PaymentStrategyInterface
{
    public function supports(Payment $payment): bool
    {
        // default strategy supports generic successful payments
        return ($payment->status ?? '') === 'success';
    }

    public function apply(Payment $payment, Order $order): bool
    {
        return DB::transaction(function () use ($payment, $order) {
            $qty = $order->quantity;
            $updated = DB::table('products')
                ->where('id', $order->product_id)
                ->where('stock', '>=', $qty)
                ->decrement('stock', $qty);

            if (! $updated) {
                return false;
            }

            $order->status = 'paid';
            $order->save();

            $payment->order_id = $order->id;
            $payment->applied = true;
            $payment->save();

            return true;
        });
    }
}
