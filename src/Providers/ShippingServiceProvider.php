<?php

declare(strict_types=1);

namespace Planx\Shipping\Providers;

use Illuminate\Support\ServiceProvider;
use Planx\Shipping\ShippingManager;

class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/shipping.php', 'shipping');

        $this->app->singleton(ShippingManager::class, fn () => new ShippingManager());
        $this->app->alias(ShippingManager::class, 'shipping');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/shipping.php' => config_path('shipping.php'),
        ], 'shipping-config');
    }
}
