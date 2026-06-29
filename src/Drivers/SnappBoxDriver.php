<?php

declare(strict_types=1);

namespace Planx\Shipping\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Planx\Shipping\Contracts\ShippingDriver;
use Throwable;

/**
 * SnappBox Shipping Driver.
 *
 * Full driver for all SnappBox B2B API interactions:
 * - Outgoing: submit orders, get pricing, track shipments, manage wallet
 * - Incoming: handle webhook status updates from SnappBox
 *
 * API version: v1.0
 * Base URL (stage):     https://stg-api.snappbox.com
 * Base URL (production): https://api.snappbox.com
 * Webhook endpoint: POST /api/snappboxwebhooks/{webhookType}
 * Reference: https://docs.snapp-box.com/docs/API/b-2-b-experience-api
 */
class SnappBoxDriver implements ShippingDriver
{
    private const MAX_RETRIES = 2;

    private const RETRY_DELAY_MS = 500;

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

    private Client $guzzle;

    private string $baseUrl = '';

    private ?string $accessToken = null;

    private ?string $refreshToken = null;

    /** Unix timestamp when the access token expires */
    private ?int $tokenExpiresAt = null;

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->guzzle = new Client();
        $this->baseUrl = mb_rtrim((string) ($config['api_base_url'] ?? 'https://stg-api.snappbox.com'), '/');

        // Authenticate if credentials are configured
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $apiToken = (string) ($config['api_token'] ?? '');

        if ($username && $password) {
            $this->login($username, $password);
        } elseif ($apiToken) {
            $this->loginByToken($apiToken);
        }
    }

    // region ShippingDriver Contract

    /**
     * Create a new order (parcel) at SnappBox.
     *
     * @param  array<string,mixed>  $payload  Order payload matching SnappBox POST /v1/orders schema
     * @return array<string,mixed>
     */
    public function createParcel(array $payload): array
    {
        return $this->apiCall('POST', '/v1/orders', $payload);
    }

    /**
     * Update an existing order at SnappBox.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function updateParcel(int|string $id, array $payload): array
    {
        return $this->apiCall('PUT', "/v1/orders/{$id}", $payload);
    }

    /**
     * Get order details by SnappBox order ID.
     *
     * @return array<string,mixed>
     */
    public function getParcel(int|string $id): array
    {
        return $this->apiCall('GET', "/v1/orders/{$id}");
    }

    /**
     * Cancel an order at SnappBox.
     *
     * @return array<string,mixed>
     */
    public function deleteParcel(int|string $id): array
    {
        return $this->apiCall('DELETE', "/v1/orders/{$id}");
    }

    /**
     * Get available working days (not supported by SnappBox API).
     *
     * @return array<string,mixed>
     */
    public function getDays(): array
    {
        return [];
    }

    /**
     * Get next 7 days structure (not supported by SnappBox API).
     *
     * @return array<string,mixed>
     */
    public function getDaysIndex(): array
    {
        return [];
    }

    // endregion

    // region SnappBox-specific Public API

    /**
     * Build a create-order payload from an Eloquent order model and form data,
     * then submit it to SnappBox.
     *
     * @param  object  $order  Order Eloquent model
     * @param  array<string,mixed>  $formData  Form data from admin panel
     * @return array<string,mixed>
     */
    public function submit(object $order, array $formData): array
    {
        $payload = $this->buildOrderPayload($order, $formData);

        return $this->createParcel($payload);
    }

    /**
     * Get pricing estimate for an order before creating it.
     *
     * @param  array<string,mixed>  $pricingPayload
     * @return array<string,mixed>  {finalCustomerFare, subsidy, totalFare} or error
     */
    public function getPricing(array $pricingPayload): array
    {
        return $this->apiCall('POST', '/v1/pricing', $pricingPayload);
    }

    /**
     * Get available delivery categories for a location.
     *
     * @return array<string,mixed>  {city, deliveryCategories: string[]}
     */
    public function getDeliveryCategories(float $latitude, float $longitude): array
    {
        return $this->apiCall('GET', '/v1/orders/delivery-categories', [], [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    /**
     * Get wallet balance.
     *
     * @return array<string,mixed>  {balance, currency}
     */
    public function getWalletBalance(): array
    {
        return $this->apiCall('GET', '/v1/wallets');
    }

    /**
     * Get order by customer reference ID.
     *
     * @return array<string,mixed>
     */
    public function getOrderByRefId(string $refId): array
    {
        return $this->apiCall('GET', "/v1/orders/references/{$refId}");
    }

    /**
     * Get list of orders.
     *
     * @param  array<string,mixed>  $query  Query parameters for filtering
     * @return array<string,mixed>
     */
    public function getOrderList(array $query = []): array
    {
        return $this->apiCall('GET', '/v1/orders/list', [], $query);
    }

    /**
     * Get current location of an order's driver.
     *
     * @return array<string,mixed>
     */
    public function getOrderLocation(int|string $orderId): array
    {
        return $this->apiCall('GET', "/v1/orders/{$orderId}/current-location");
    }

    /**
     * Get order events/history.
     *
     * @return array<string,mixed>
     */
    public function getOrderEvents(int|string $orderId): array
    {
        return $this->apiCall('GET', "/v1/orders/{$orderId}/events");
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
     * @return array<string,mixed> Response for the controller
     *
     * @throws InvalidArgumentException
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
        } catch (Throwable $e) {
            Log::error('SnappBox webhook failed', [
                'type' => $webhookType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    // endregion

    // region Auth

    /**
     * Authenticate with SnappBox using username (phone) and password.
     */
    private function login(string $username, string $password): void
    {
        try {
            $response = $this->guzzle->post($this->baseUrl . '/v1/auth/login', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'username' => $username,
                    'password' => $password,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            $this->accessToken = $body['access_token'] ?? null;
            $this->refreshToken = $body['refresh_token'] ?? null;

            // SnappBox tokens typically expire after 1 hour; default to 55 min for safety
            $this->tokenExpiresAt = time() + 3300;
        } catch (Throwable $e) {
            $this->accessToken = null;
            $this->refreshToken = null;
            $this->tokenExpiresAt = null;
            Log::error('SnappBox login failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Authenticate with SnappBox using a long-lived API token.
     */
    private function loginByToken(string $apiToken): void
    {
        try {
            $response = $this->guzzle->post($this->baseUrl . '/v1/auth/login-by-token', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'token' => $apiToken,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            $this->accessToken = $body['access_token'] ?? null;
            $this->refreshToken = $body['refresh_token'] ?? null;
            $this->tokenExpiresAt = time() + 3300;
        } catch (Throwable $e) {
            $this->accessToken = null;
            $this->refreshToken = null;
            $this->tokenExpiresAt = null;
            Log::error('SnappBox login-by-token failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Refresh the access token using the refresh token.
     */
    private function refreshAccessToken(): void
    {
        if ( ! $this->refreshToken || ! $this->accessToken) {
            return;
        }

        try {
            $response = $this->guzzle->post($this->baseUrl . '/v1/auth/refresh-token', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'token' => $this->accessToken,
                    'refresh_token' => $this->refreshToken,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            $this->accessToken = $body['access_token'] ?? null;
            $this->refreshToken = $body['refresh_token'] ?? null;
            $this->tokenExpiresAt = time() + 3300;
        } catch (Throwable $e) {
            Log::error('SnappBox token refresh failed: ' . $e->getMessage());

            // Fall back to re-authentication
            $username = (string) ($this->config['username'] ?? '');
            $password = (string) ($this->config['password'] ?? '');
            $apiToken = (string) ($this->config['api_token'] ?? '');

            if ($username && $password) {
                $this->login($username, $password);
            } elseif ($apiToken) {
                $this->loginByToken($apiToken);
            }
        }
    }

    /**
     * Ensure we have a valid access token, refreshing if needed.
     *
     * @throws \RuntimeException When no credentials are configured
     */
    private function ensureToken(): void
    {
        if ( ! $this->accessToken || ($this->tokenExpiresAt && time() >= $this->tokenExpiresAt - 60)) {
            if ($this->refreshToken) {
                $this->refreshAccessToken();

                return;
            }

            $username = (string) ($this->config['username'] ?? '');
            $password = (string) ($this->config['password'] ?? '');
            $apiToken = (string) ($this->config['api_token'] ?? '');

            if ($username && $password) {
                $this->login($username, $password);
            } elseif ($apiToken) {
                $this->loginByToken($apiToken);
            } else {
                throw new \RuntimeException(
                    'SnappBox authentication not configured. Set SNAPPBOX_USERNAME/SNAPPBOX_PASSWORD or SNAPPBOX_API_TOKEN.'
                );
            }
        }
    }

    // endregion

    // region API Communication

    /**
     * Make an authenticated API call to SnappBox with retry logic.
     *
     * @param  string  $method  HTTP method (GET, POST, PUT, DELETE)
     * @param  string  $path  API path (e.g. /v1/orders)
     * @param  array<string,mixed>  $jsonBody  JSON body for POST/PUT requests
     * @param  array<string,mixed>  $query  Query parameters for GET requests
     * @return array<string,mixed>
     */
    private function apiCall(string $method, string $path, array $jsonBody = [], array $query = []): array
    {
        $lastError = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep(self::RETRY_DELAY_MS * 1000);
                Log::info('SnappBox retry attempt ' . ($attempt + 1) . ' for ' . $method . ' ' . $path);
            }

            try {
                $this->ensureToken();
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'error' => ['message' => 'SnappBox auth error: ' . $e->getMessage()],
                ];
            }

            try {
                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                ];

                if ($jsonBody !== []) {
                    $options['json'] = $jsonBody;
                }

                if ($query !== []) {
                    $options['query'] = $query;
                }

                $response = match (strtoupper($method)) {
                    'GET' => $this->guzzle->get($this->baseUrl . $path, $options),
                    'POST' => $this->guzzle->post($this->baseUrl . $path, $options),
                    'PUT' => $this->guzzle->put($this->baseUrl . $path, $options),
                    'DELETE' => $this->guzzle->delete($this->baseUrl . $path, $options),
                    default => throw new InvalidArgumentException("Unsupported HTTP method: {$method}"),
                };

                $result = json_decode($response->getBody()->getContents(), true);

                return is_array($result) ? $result : ['raw' => (string) $response->getBody()];
            } catch (GuzzleException $e) {
                $errorMsg = $e->getMessage();

                // Try to extract error message from API response
                if ($e->getResponse()) {
                    $responseBody = (string) $e->getResponse()->getBody();
                    $decoded = json_decode($responseBody, true);
                    if (isset($decoded['message'])) {
                        $errorMsg = $decoded['message'];
                    }
                }

                $lastError = $errorMsg;
                Log::warning('SnappBox API call attempt ' . ($attempt + 1) . ' failed: ' . $errorMsg);

                if ($attempt >= self::MAX_RETRIES) {
                    break;
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('SnappBox API call attempt ' . ($attempt + 1) . ' failed: ' . $lastError);

                if ($attempt >= self::MAX_RETRIES) {
                    break;
                }
            }
        }

        Log::error('SnappBox API call failed after ' . (self::MAX_RETRIES + 1) . ' attempts: ' . $lastError);

        return [
            'success' => false,
            'error' => ['message' => 'SnappBox API error: ' . $lastError],
        ];
    }

    // endregion

    // region Payload Building

    /**
     * Build a complete SnappBox order payload from an Eloquent order model and form data.
     *
     * @param  object  $order  Order Eloquent model
     * @param  array<string,mixed>  $formData  Form data from admin panel
     * @return array<string,mixed>
     */
    private function buildOrderPayload(object $order, array $formData): array
    {
        // Extract address data if available
        $addressLat = '';
        $addressLng = '';
        $addressStr = '';
        $addressPhone = '';
        $addressContactName = '';

        if (method_exists($order, 'address') && isset($order->address)) {
            $addr = $order->address;
            $addressLat = (string) ($addr->latitude ?? '');
            $addressLng = (string) ($addr->longitude ?? '');
            $addressStr = (string) ($addr->address ?? '');
            $addressPhone = (string) ($addr->phone ?? '');
            $addressContactName = trim(
                ((string) ($addr->first_name ?? '')) . ' ' . ((string) ($addr->last_name ?? ''))
            );
        }

        // Resolve phone: form data > order model > address
        $phone = (string) ($formData['receiver_mobile'] ?? $order->mobile ?? $addressPhone);

        // Resolve contact name
        $firstName = (string) ($formData['receiver_first_name'] ?? $order->shipping_first_name ?? '');
        $lastName = (string) ($formData['receiver_last_name'] ?? $order->shipping_last_name ?? '');
        $contactName = trim($firstName . ' ' . $lastName) ?: $addressContactName;

        // Destination lat/lng
        $destLat = (string) ($formData['destination_lat'] ?? $order->shipping_lat ?? $addressLat);
        $destLng = (string) ($formData['destination_lng'] ?? $order->shipping_lng ?? $addressLng);
        $destAddress = (string) ($formData['destination_address'] ?? $order->shipping_address ?? $addressStr);

        // Origin data (sender / pickup terminal)
        $originLat = (string) ($formData['origin_lat'] ?? $this->config['origin_lat'] ?? '');
        $originLng = (string) ($formData['origin_lng'] ?? $this->config['origin_lng'] ?? '');
        $originAddress = (string) ($formData['origin_address'] ?? $this->config['origin_address'] ?? '');
        $originContactName = (string) ($formData['origin_contact_name'] ?? $this->config['origin_contact_name'] ?? '');
        $originPhone = (string) ($formData['origin_phone'] ?? $this->config['origin_phone'] ?? '');

        // Delivery category & city
        $deliveryCategory = (string) ($formData['delivery_category'] ?? $this->config['delivery_category'] ?? 'bike');
        $city = (string) ($formData['city'] ?? $this->config['city'] ?? 'tehran');

        // Payment type
        $isCod = ($order->payment_type ?? '') === 'cod';
        $paymentType = $isCod ? 'cod' : 'prepaid';

        // Build items array from order products
        $items = $this->buildItemsArray($order, $formData);

        // Calculate total package value
        $totalPackageValue = (int) ($order->grand_total ?? 0);

        // Time slot
        $startTime = (string) ($formData['start_time'] ?? '');
        $endTime = (string) ($formData['end_time'] ?? '');

        // refId: unique customer reference for the order
        $refId = (string) ($formData['ref_id'] ?? ($this->config['ref_id_prefix'] ?? 'ORD-') . $order->id);

        $payload = [
            'city' => $city,
            'deliveryCategory' => $deliveryCategory,
            'hasReturn' => (bool) ($formData['has_return'] ?? false),
            'paymentType' => $paymentType,
            'refId' => $refId,
            'podEnabled' => (bool) ($formData['pod_enabled'] ?? false),
            'popEnabled' => (bool) ($formData['pop_enabled'] ?? false),
            'voucherCode' => (string) ($formData['voucher_code'] ?? ''),
            'waitingTime' => (int) ($formData['waiting_time'] ?? 0),
            'packages' => [
                [
                    'pickupReference' => '1',
                    'dropoffReference' => '2',
                    'insuranceId' => (int) ($formData['insurance_id'] ?? 0),
                    'items' => $items,
                ],
            ],
            'terminals' => [
                [
                    'type' => 'pickup',
                    'reference' => '1',
                    'address' => $originAddress,
                    'latitude' => $originLat,
                    'longitude' => $originLng,
                    'contactName' => $originContactName,
                    'phoneNumber' => $originPhone,
                    'status' => 'PENDING',
                    'codeGenerationStrategy' => 'AUTO',
                    'comment' => (string) ($formData['origin_comment'] ?? ''),
                ],
                [
                    'type' => 'dropoff',
                    'reference' => '2',
                    'address' => $destAddress,
                    'latitude' => $destLat,
                    'longitude' => $destLng,
                    'contactName' => $contactName,
                    'phoneNumber' => $phone,
                    'status' => 'PENDING',
                    'codeGenerationStrategy' => (bool) ($formData['pod_enabled'] ?? false) ? 'AUTO' : 'MANUAL',
                    'comment' => (string) ($formData['destination_comment'] ?? ''),
                ],
            ],
        ];

        // Add time slot if provided
        if ($startTime) {
            $payload['startTime'] = $startTime;
        }
        if ($endTime) {
            $payload['endTime'] = $endTime;
        }

        // Add sequence number if provided
        if (isset($formData['sequence_number'])) {
            $payload['sequenceNumberDeliveryCollection'] = (int) $formData['sequence_number'];
        }

        return $payload;
    }

    /**
     * Build the items array from order model products.
     *
     * @param  object  $order
     * @param  array<string,mixed>  $formData
     * @return array<int, array<string,mixed>>
     */
    private function buildItemsArray(object $order, array $formData): array
    {
        $items = [];

        // If order has products relationship, build from products
        if (method_exists($order, 'products') && isset($order->products)) {
            foreach ($order->products as $product) {
                $items[] = [
                    'name' => (string) ($product->name ?? $product->title ?? 'محصول'),
                    'packageValue' => (int) ($product->price ?? $product->grand_total ?? 0),
                    'quantity' => (int) ($product->pivot->quantity ?? $product->quantity ?? 1),
                    'quantityMeasuringUnit' => 'عدد',
                    'volume' => (int) ($product->volume ?? $product->shipping_volume ?? 0),
                    'weight' => (int) ($product->weight ?? $product->shipping_weight ?? 0),
                ];
            }
        }

        // Fallback: single item from order total
        if ($items === []) {
            $items[] = [
                'name' => (string) ($formData['item_name'] ?? 'سفارش #' . $order->id),
                'packageValue' => (int) ($order->grand_total ?? 0),
                'quantity' => 1,
                'quantityMeasuringUnit' => 'عدد',
                'volume' => (int) ($formData['item_volume'] ?? 0),
                'weight' => (int) ($order->shipping_weight ?? $formData['item_weight'] ?? 0),
            ];
        }

        return $items;
    }

    // endregion

    // region Internal

    /**
     * Validate the webhook payload has minimum required fields.
     *
     * @throws InvalidArgumentException
     */
    private function validatePayload(string $webhookType, array $payload): void
    {
        $errors = [];

        if ('' === ($payload['orderId'] ?? '')) {
            $errors[] = 'Missing required field: orderId';
        }

        $hasRef = '' !== ($payload['customerRefId'] ?? '');
        $hasRefs = isset($payload['customerRefIds']) && is_array($payload['customerRefIds']) && [] !== $payload['customerRefIds'];
        if ( ! $hasRef && ! $hasRefs) {
            $errors[] = 'Missing required field: customerRefId or customerRefIds';
        }

        if ('' === ($payload['orderStatus'] ?? '')) {
            $errors[] = 'Missing required field: orderStatus';
        }

        if ([] !== $errors) {
            throw new InvalidArgumentException('Validation failed: ' . implode('; ', $errors));
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
