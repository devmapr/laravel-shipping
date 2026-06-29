<?php

declare(strict_types=1);

namespace Planx\Shipping\Tests\Unit;

use InvalidArgumentException;
use Planx\Shipping\Drivers\SnappBoxDriver;
use Planx\Shipping\Tests\TestCase;

class SnappBoxDriverTest extends TestCase
{
    private SnappBoxDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new SnappBoxDriver(config('shipping.drivers.snappbox.config'));
    }

    // region Contract compliance

    public function test_implements_shipping_driver_interface(): void
    {
        $this->assertInstanceOf(
            \Planx\Shipping\Contracts\ShippingDriver::class,
            $this->driver,
        );
    }

    public function test_create_parcel_without_auth_returns_error(): void
    {
        $result = $this->driver->createParcel([]);

        $this->assertIsArray($result);
        // Without configured credentials, API call fails with auth error
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('message', $result['error']);
    }

    public function test_update_parcel_without_auth_returns_error(): void
    {
        $result = $this->driver->updateParcel(1, []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('message', $result['error']);
    }

    public function test_get_parcel_without_auth_returns_error(): void
    {
        $result = $this->driver->getParcel(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('message', $result['error']);
    }

    public function test_delete_parcel_without_auth_returns_error(): void
    {
        $result = $this->driver->deleteParcel(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('message', $result['error']);
    }

    public function test_get_days_returns_empty_array(): void
    {
        $this->assertSame([], $this->driver->getDays());
        $this->assertSame([], $this->driver->getDaysIndex());
    }

    // endregion

    // region Webhook validation (invalid input)

    public function test_webhook_rejects_missing_order_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orderId');

        $this->driver->handleWebhook('delivered', [
            'customerRefId' => 'ref-123',
            'orderStatus' => 'DELIVERED',
        ]);
    }

    public function test_webhook_rejects_missing_customer_ref(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('customerRefId');

        $this->driver->handleWebhook('delivered', [
            'orderId' => '999',
            'orderStatus' => 'DELIVERED',
        ]);
    }

    public function test_webhook_rejects_missing_order_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orderStatus');

        $this->driver->handleWebhook('delivered', [
            'orderId' => '999',
            'customerRefId' => 'ref-123',
        ]);
    }

    public function test_webhook_rejects_empty_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->driver->handleWebhook('delivered', []);
    }

    // endregion

    // region Webhook handling (valid input — with DB transaction)

    public function test_webhook_handles_accepted_event(): void
    {
        $result = $this->driver->handleWebhook('accepted', [
            'webhookType' => 'ORDER_ACCEPTED',
            'orderId' => '1001',
            'orderStatus' => 'ACCEPTED',
            'customerRefId' => 'ref-100',
            'bikerName' => 'حامد قلی زاده',
            'bikerPhone' => '8904884293',
            'bikerId' => 'BK-001',
            'bikerPhotoUrl' => 'http://url',
            'latitude' => 36.829404,
            'longitude' => 54.0046316,
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_webhook_handles_delivered_event(): void
    {
        $result = $this->driver->handleWebhook('delivered', [
            'webhookType' => 'ORDER_STATUS_UPDATE',
            'orderId' => '2154',
            'orderStatus' => 'DELIVERED',
            'customerRefId' => 'f184eb6d-785f-4065-934cb-c29234118dcbc7',
            'bikerName' => 'حامد قلی زاده',
            'bikerPhone' => '8904884293',
            'bikerId' => 'BK-001',
            'totalFare' => 615000,
            'customerDeliveryFare' => 615000,
            'latitude' => 36.829404,
            'longitude' => 54.0046316,
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_webhook_handles_cancelled_event(): void
    {
        $result = $this->driver->handleWebhook('canceled', [
            'webhookType' => 'ORDER_CANCELLED',
            'orderId' => '3003',
            'orderStatus' => 'CANCELLED',
            'customerRefId' => 'ref-300',
            'bikerName' => 'test biker',
            'bikerPhone' => '+98912...',
            'bikerId' => 'BK-003',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_webhook_handles_invoice_update(): void
    {
        $result = $this->driver->handleWebhook('invoice-update', [
            'orderId' => '4004',
            'orderStatus' => 'PENDING',
            'webhookType' => 'INVOICE_STATUS_UPDATE',
            'customerRefId' => 'ref-400',
            'invoiceId' => 'INV-001',
            'invoiceStatus' => 'SUCCESS',
            'invoiceDirection' => 'CREDITOR',
            'actionBy' => 'system',
            'batch' => false,
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_webhook_handles_batch_order(): void
    {
        $result = $this->driver->handleWebhook('delivered', [
            'webhookType' => 'ORDER_STATUS_UPDATE',
            'orderId' => '5005',
            'orderStatus' => 'DELIVERED',
            'customerRefIds' => ['ref-501', 'ref-502', 'ref-503'],
            'batch' => true,
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_webhook_handles_failed_delivery(): void
    {
        $result = $this->driver->handleWebhook('failed-deliver', [
            'webhookType' => 'FAILED_DELIVERY',
            'orderId' => '6006',
            'orderStatus' => 'PICKED_UP',
            'customerRefId' => 'ref-600',
            'bikerName' => 'test biker',
            'bikerPhone' => '+98912...',
            'bikerId' => 'BK-006',
            'batch' => true,
            'sequenceNumber' => '2',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_webhook_handles_return_to_source(): void
    {
        $result = $this->driver->handleWebhook('failed-deliver-return-to-source', [
            'webhookType' => 'FAILED_DELIVER_RETURN_TO_SOURCE',
            'orderId' => '7007',
            'orderStatus' => 'DELIVERED',
            'customerRefId' => 'ref-700',
            'bikerName' => 'test biker',
            'bikerPhone' => '+98912...',
            'bikerId' => 'BK-007',
            'batch' => true,
            'actionBy' => 'BIKER',
            'sequenceNumber' => '2',
        ]);

        $this->assertTrue($result['success']);
    }

    // endregion

    // region New API methods (without credentials — expect error responses)

    public function test_get_delivery_categories_without_auth_returns_error(): void
    {
        $result = $this->driver->getDeliveryCategories(35.757523, 51.409911);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_wallet_balance_without_auth_returns_error(): void
    {
        $result = $this->driver->getWalletBalance();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_pricing_without_auth_returns_error(): void
    {
        $result = $this->driver->getPricing([
            'city' => 'tehran',
            'deliveryCategory' => 'bike',
            'terminals' => [
                ['type' => 'pickup', 'latitude' => '35.757', 'longitude' => '51.409', 'address' => 'test'],
                ['type' => 'dropoff', 'latitude' => '35.758', 'longitude' => '51.410', 'address' => 'test2'],
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_order_by_ref_id_without_auth_returns_error(): void
    {
        $result = $this->driver->getOrderByRefId('ref-123');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_order_list_without_auth_returns_error(): void
    {
        $result = $this->driver->getOrderList();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_order_location_without_auth_returns_error(): void
    {
        $result = $this->driver->getOrderLocation(123);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_order_events_without_auth_returns_error(): void
    {
        $result = $this->driver->getOrderEvents(123);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_submit_without_auth_returns_error(): void
    {
        $order = (object) [
            'id' => 1,
            'grand_total' => 150000,
            'payment_type' => 'prepaid',
            'shipping_first_name' => 'Ali',
            'shipping_last_name' => 'Rezaei',
            'shipping_lat' => '35.758',
            'shipping_lng' => '51.410',
            'shipping_address' => 'Tehran, Vanak',
            'shipping_weight' => 500,
            'mobile' => '09121112233',
        ];

        $result = $this->driver->submit($order, []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // endregion
}
