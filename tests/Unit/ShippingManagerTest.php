<?php

declare(strict_types=1);

namespace Planx\Shipping\Tests\Unit;

use InvalidArgumentException;
use Planx\Shipping\Contracts\ShippingDriver;
use Planx\Shipping\Drivers\ForwardDriver;
use Planx\Shipping\Drivers\SnappBoxDriver;
use Planx\Shipping\Drivers\TinexDriver;
use Planx\Shipping\ShippingManager;
use Planx\Shipping\Tests\TestCase;

class ShippingManagerTest extends TestCase
{
    private ShippingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new ShippingManager();
    }

    public function test_resolves_default_driver(): void
    {
        $driver = $this->manager->driver();

        $this->assertInstanceOf(ShippingDriver::class, $driver);
    }

    public function test_resolves_snappbox_driver(): void
    {
        $driver = $this->manager->driver('snappbox');

        $this->assertInstanceOf(SnappBoxDriver::class, $driver);
    }

    public function test_resolves_tinex_driver(): void
    {
        $this->app['config']->set('shipping.drivers.tinex', [
            'class' => TinexDriver::class,
            'config' => [
                'base_url' => 'https://test.tinex.com',
                'username' => '',
                'password' => '',
            ],
        ]);

        $driver = $this->manager->driver('tinex');

        $this->assertInstanceOf(TinexDriver::class, $driver);
    }

    public function test_resolves_forward_driver(): void
    {
        $this->app['config']->set('shipping.drivers.forward', [
            'class' => ForwardDriver::class,
            'config' => [],
        ]);

        $driver = $this->manager->driver('forward');

        $this->assertInstanceOf(ForwardDriver::class, $driver);
    }

    public function test_throws_on_unknown_driver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-existent');

        $this->manager->driver('non-existent');
    }

    public function test_same_driver_is_cached(): void
    {
        $first = $this->manager->driver('snappbox');
        $second = $this->manager->driver('snappbox');

        $this->assertSame($first, $second);
    }
}
