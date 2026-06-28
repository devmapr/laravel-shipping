<?php

declare(strict_types=1);

namespace Planx\Shipping\Drivers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Planx\Shipping\Contracts\ShippingDriver;

/**
 * SnappBox Shipping Driver.
 *
 * Self-contained driver for all SnappBox API interactions:
 * - Outgoing: submit orders to SnappBox (TODO — pending API credentials)
 * - Incoming: handle webhook status updates from SnappBox
 *
 * Webhook endpoint: POST /api/snappboxwebhooks/{webhookType}
 * Reference: SnappBox OpenAPI spec v1.0.0
 */
class SnappBoxDriver implements ShippingDriver
{
    /** @var array<string, string> Webhook type labels in Persian */
    private const LABELS = [
        'accepted' => 'پیک به سفارش تخصیص داده شد',
        'arrived' => 'پیک به مبدأ رسید',
        'pickedup' => 'مرسوله تحویل پیک شد',
        'arrivedAtDropOff' => 'پیک به مقصد رسید',
        'delivered' => 'مرسوله تحویل داده شد',
        'canceled' => 'سفارش لغو شد',
        'canceled-by-system' => 'سفارش توسط سیستم لغو شد',
        'canceled-allocation' => 'انتصاب پیک لغو شد',
        'invoice-update' => 'بروزرسانی صورتحساب',
        'failed-deliver' => 'تحویل ناموفق',
        'failed-deliver-add-return' => 'تحویل ناموفق - مرجوعی اضافه شد',
        'failed-deliver-return-to-source' => 'مرسوله به مبدأ بازگشت',
    ];

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    // region ShippingDriver Contract (stubs — outgoing not yet implemented)

    public function createParcel(array $payload): array
    {
        return ['message' => 'Not implemented yet — SnappBox outgoing API is pending'];
    }

    public function updateParcel(int|string $id, array $payload): array
    {
        return ['message' => 'Not supported'];
    }

    public function getParcel(int|string $id): array
    {
        return ['message' => 'Not supported'];
    }

    public function deleteParcel(int|string $id): array
    {
        return ['message' => 'Not supported'];
    }

    public function getDays(): array
    {
        return [];
    }

    public function getDaysIndex(): array
    {
        return [];
    }

    // endregion

    // region Webhook Handling

    /**
     * Process an incoming webhook from SnappBox.
     *
     * Validates the payload, logs the event, and executes
     * any business logic inside a database transaction.
     *
     * @param  string  $webhookType  URL fragment (e.g. 'delivered', 'accepted')
     * @param  array<string,mixed>  $payload  Raw JSON payload
     * @return array<string,mixed>  Response for the controller
     *
     * @throws \InvalidArgumentException
     */
    public function handleWebhook(string $webhookType, array $payload): array
    {
        // 1. Validate
        $this->validatePayload($webhookType, $payload);

        // 2. Process inside a transaction
        try {
            DB::transaction(function () use ($webhookType, $payload): void {
                $this->processWebhook($webhookType, $payload);
            });

            Log::info('SnappBox webhook processed', [
                'type' => $webhookType,
                'order_id' => $payload['orderId'] ?? '?',
                'customer_ref_id' => $payload['customerRefId'] ?? '?',
            ]);

            return ['success' => true, 'message' => 'Webhook received and processed'];
        } catch (\Throwable $e) {
            Log::error('SnappBox webhook failed', [
                'type' => $webhookType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    // endregion

    // region Internal

    /**
     * Validate the webhook payload has minimum required fields.
     *
     * @throws \InvalidArgumentException
     */
    private function validatePayload(string $webhookType, array $payload): void
    {
        $errors = [];

        if ('' === ($payload['orderId'] ?? '')) {
            $errors[] = 'Missing required field: orderId';
        }

        $hasRef = '' !== ($payload['customerRefId'] ?? '');
        $hasRefs = isset($payload['customerRefIds']) && is_array($payload['customerRefIds']) && [] !== $payload['customerRefIds'];
        if (! $hasRef && ! $hasRefs) {
            $errors[] = 'Missing required field: customerRefId or customerRefIds';
        }

        if ('' === ($payload['orderStatus'] ?? '')) {
            $errors[] = 'Missing required field: orderStatus';
        }

        if ([] !== $errors) {
            throw new \InvalidArgumentException('Validation failed: ' . implode('; ', $errors));
        }
    }

    /**
     * Process the webhook payload.
     *
     * Currently logs the event. Override/extend this method to add
     * order status updates, notifications, etc.
     */
    private function processWebhook(string $webhookType, array $payload): void
    {
        $label = self::LABELS[$webhookType] ?? $webhookType;

        Log::info("SnappBox webhook: {$label}", [
            'type' => $webhookType,
            'order_id' => $payload['orderId'] ?? null,
            'order_status' => $payload['orderStatus'] ?? null,
            'customer_ref_id' => $payload['customerRefId'] ?? null,
            'batch' => $payload['batch'] ?? false,
            'biker_name' => $payload['bikerName'] ?? null,
            'biker_phone' => $payload['bikerPhone'] ?? null,
            'biker_id' => $payload['bikerId'] ?? null,
            'total_fare' => $payload['totalFare'] ?? null,
            'customer_delivery_fare' => $payload['customerDeliveryFare'] ?? null,
            'sequence_number' => $payload['sequenceNumber'] ?? null,
            'action_by' => $payload['actionBy'] ?? null,
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'invoice_id' => $payload['invoiceId'] ?? null,
            'invoice_status' => $payload['invoiceStatus'] ?? null,
            'invoice_direction' => $payload['invoiceDirection'] ?? null,
        ]);

        // TODO: Add business logic per webhook type:
        // match ($webhookType) {
        //     'delivered' => $this->markOrderDelivered($payload),
        //     'canceled', 'canceled-by-system' => $this->markOrderCancelled($payload),
        //     ...
        // }
    }

    // endregion
}
