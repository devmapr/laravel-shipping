<?php

declare(strict_types=1);

namespace Planx\Shipping\Tests\Feature;

use Planx\Shipping\Facades\Shipping;
use Planx\Shipping\Tests\TestCase;

class FacadeTest extends TestCase
{
    public function test_facade_returns_valid_driver(): void
    {
        $driver = Shipping::driver('snappbox');

        $this->assertInstanceOf(
            \Planx\Shipping\Drivers\SnappBoxDriver::class,
            $driver,
        );
    }

    public function test_facade_defaults_to_config(): void
    {
        $driver = Shipping::driver();

        $this->assertInstanceOf(
            \Planx\Shipping\Contracts\ShippingDriver::class,
            $driver,
        );
    }

    public function test_webhook_via_facade_integration(): void
    {
        $driver = Shipping::driver('snappbox');

        $result = $driver->handleWebhook('pickedup', [
            'webhookType' => 'ORDER_STATUS_UPDATE',
            'orderId' => '9999',
            'orderStatus' => 'PICKED_UP',
            'customerRefId' => 'integration-test',
            'bikerName' => 'Test',
            'bikerPhone' => '000',
            'bikerId' => 'B-1',
            'totalFare' => 100000,
            'sequenceNumber' => '1',
            'latitude' => 35.6892,
            'longitude' => 51.3890,
        ]);

        $this->assertTrue($result['success']);
    }
}
