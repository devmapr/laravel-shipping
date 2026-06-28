<?php

declare(strict_types=1);

namespace Planx\Shipping;

use InvalidArgumentException;
use Planx\Shipping\Contracts\ShippingDriver;

class ShippingManager
{
    /** @var array<string,ShippingDriver> */
    protected array $drivers = [];

    /**
     * Get driver by name (from config). Falls back to default.
     */
    public function driver(?string $name = null): ShippingDriver
    {
        $name = $name ?: (string) config('shipping.default');
        if ( ! isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->resolve($name);
        }

        return $this->drivers[$name];
    }

    /**
     * Resolve driver instance using config mapping.
     */
    protected function resolve(string $name): ShippingDriver
    {
        $drivers = (array) config('shipping.drivers');
        $driverClass = $drivers[$name]['class'] ?? null;

        if ( ! $driverClass || ! class_exists($driverClass)) {
            throw new InvalidArgumentException("Shipping driver [{$name}] is not defined or class not found.");
        }

        $config = $drivers[$name]['config'] ?? [];

        return new $driverClass($config);
    }
}
