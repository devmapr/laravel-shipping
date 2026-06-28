<?php

declare(strict_types=1);

namespace Planx\Shipping\Tests;

use PHPUnit\Framework\TestCase;
use Planx\Shipping\Drivers\SnappBoxDriver;

class SnappBoxDriverTest extends TestCase
{
    private SnappBoxDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new SnappBoxDriver([
            'active' => true,
            'webhook_token' => 'test-token',
        ]);
    }

    public function test_driver_implements_interface(): void
    {
        $this->assertInstanceOf(
            \Planx\Shipping\Contracts\ShippingDriver::class,
            $this->driver,
        );
    }

    public function test_create_parcel_returns_pending_message(): void
    {
        $result = $this->driver->createParcel([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('pending', $result['message']);
    }

    public function test_update_parcel_returns_not_supported(): void
    {
        $result = $this->driver->updateParcel(1, []);

        $this->assertIsArray($result);
        $this->assertStringContainsString('Not supported', $result['message']);
    }

    public function test_handle_webhook_validates_missing_order_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('orderId');

        $this->driver->handleWebhook('delivered', [
            'customerRefId' => '123',
            'orderStatus' => 'DELIVERED',
        ]);
    }

    public function test_handle_webhook_validates_missing_customer_ref(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('customerRefId');

        $this->driver->handleWebhook('delivered', [
            'orderId' => '999',
            'orderStatus' => 'DELIVERED',
        ]);
    }

    public function test_get_days_returns_empty_array(): void
    {
        $this->assertSame([], $this->driver->getDays());
    }

    public function test_get_days_index_returns_empty_array(): void
    {
        $this->assertSame([], $this->driver->getDaysIndex());
    }
}
