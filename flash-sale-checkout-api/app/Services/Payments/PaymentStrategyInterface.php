<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Order;

interface PaymentStrategyInterface
{
    // whether this strategy supports handling the given payment
    public function supports(Payment $payment): bool;

    // attempt to apply the payment to the order; return true on success
    public function apply(Payment $payment, Order $order): bool;
}
