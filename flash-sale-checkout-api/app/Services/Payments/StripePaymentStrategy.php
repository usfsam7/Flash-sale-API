<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Order;

class StripePaymentStrategy implements PaymentStrategyInterface
{
    public function supports(Payment $payment): bool
    {
        return (($payment->gateway ?? '') === 'stripe');
    }

    public function apply(Payment $payment, Order $order): bool
    {
        // Stripe-specific handling would go here. Keep contract: return bool.
        // For now behave similarly to default but avoid direct DB calls here.
        $order->status = 'paid';
        $order->save();

        $payment->order_id = $order->id;
        $payment->applied = true;
        $payment->save();

        return true;
    }
}
