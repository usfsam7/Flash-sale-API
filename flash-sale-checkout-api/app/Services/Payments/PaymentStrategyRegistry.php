<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Order;

class PaymentStrategyRegistry
{
    /** @var PaymentStrategyInterface[] */
    private array $strategies;

    public function __construct(iterable $strategies = [])
    {
        $this->strategies = is_array($strategies) ? $strategies : iterator_to_array($strategies);
    }

    public function getStrategyFor(Payment $payment): ?PaymentStrategyInterface
    {
        foreach ($this->strategies as $s) {
            if ($s->supports($payment)) {
                return $s;
            }
        }

        return null;
    }

    public function applyBest(Payment $payment, Order $order): bool
    {
        $s = $this->getStrategyFor($payment);
        if (! $s) {
            return false;
        }
        return $s->apply($payment, $order);
    }
}
