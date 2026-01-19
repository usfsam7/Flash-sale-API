<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Payments\DefaultPaymentStrategy;
use App\Services\Payments\StripePaymentStrategy;
use App\Services\Payments\PaymentStrategyRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // bind individual strategies so the container can build them
        $this->app->bind(DefaultPaymentStrategy::class, DefaultPaymentStrategy::class);
        $this->app->bind(StripePaymentStrategy::class, StripePaymentStrategy::class);

        // register a singleton registry that receives the available strategies
        $this->app->singleton(PaymentStrategyRegistry::class, function ($app) {
            return new PaymentStrategyRegistry([
                $app->make(DefaultPaymentStrategy::class),
                $app->make(StripePaymentStrategy::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
