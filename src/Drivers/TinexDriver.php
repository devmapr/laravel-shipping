<?php

declare(strict_types=1);

namespace Planx\Shipping\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Planx\Shipping\Contracts\ShippingDriver;
use Throwable;

/**
 * Next One Shipping Driver (Tinex)
 *
 * API Docs: Next One سند سرویس های سامانه
 *
 * Auth:      POST /Auth/auth/login        → {username, password, clientType:1}
 * Order:     POST /order/orders/Create-and-finalize
 *
 * Parcel size table (parcelSizeId):
 *   1=10x15x10,  2=15x20x10,  3=20x20x15,  4=20x30x20,  5=25x35x20,
 *   6=25x45x20,  7=30x40x25,  8=40x45x30,  9=45x55x35, 10=پاکت (0x0x0)
 */
class TinexDriver implements ShippingDriver
{
    private const MAX_RETRIES = 2;

    private const RETRY_DELAY_MS = 500;

    /** @var array<string,mixed> */
    private array $config;

    private Client $guzzle;

    private string $baseUrl = '';

    private ?string $token = null;

    /** Unix timestamp when the token expires */
    private ?int $tokenExpiresAt = null;

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->guzzle = new Client();
        $this->baseUrl = mb_rtrim((string) ($config['base_url'] ?? 'https://stg-apiback.tinextco.com'), '/');

        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        if ($username && $password) {
            $this->login($username, $password);
        }
    }

    /**
     * Return the Next One parcel size options for UI dropdowns.
     *
     * @return array<int, string>
     */
    public static function getSizeOptions(): array
    {
        return [
            1 => 'بسته سایز 1 (10×15×10)',
            2 => 'بسته سایز 2 (15×20×10)',
            3 => 'بسته سایز 3 (20×20×15)',
            4 => 'بسته سایز 4 (20×30×20)',
            5 => 'بسته سایز 5 (25×35×20)',
            6 => 'بسته سایز 6 (25×45×20)',
            7 => 'بسته سایز 7 (30×40×25)',
            8 => 'بسته سایز 8 (40×45×30)',
            9 => 'بسته سایز 9 (45×55×35)',
            10 => 'پاکت (0×0×0)',
        ];
    }

    // region Contract Methods (no-op for Tinex — use submit())

    public function createParcel(array $payload): array
    {
        return ['message' => 'Use submit()'];
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

    // region Next One API — Create & Finalize Order

    /**
     * Submit a new order to Next One (create + finalize in one call).
     *
     * @param  array<string,mixed>|object  $order  Order model/object with shipping fields
     * @param  array<string,mixed>  $formData  Form data from EditOrder panel:
     *                                         - parcel_size_id: int (Next One size ID, 1-10)
     *                                         - is_self_pickup: bool
     *                                         - delivery_code: ?string (POD code)
     *                                         - region: ?string
     */
    public function submit($order, array $formData): array
    {
        try {
            $this->ensureToken();
        } catch (Throwable $e) {
            Log::error('Tinex submit failed (auth): ' . $e->getMessage());

            return [
                'success' => false,
                'error' => ['message' => 'خطا در احراز هویت تینکس: ' . $e->getMessage()],
            ];
        }

        $orderObj = (object) $order;
        $internalBarcode = ($this->config['barcode_prefix'] ?? 'TINX_') . (string) $orderObj->id;
        $isCod = ($orderObj->payment_type ?? '') === 'cod';

        $parcelSizeId = (int) ($formData['parcel_size_id'] ?? $this->config['default_parcel_size_id'] ?? 3);

        $payload = [
            'originContactId' => (int) ($this->config['origin_contact_id'] ?? 28),
            'isSelfPickup' => (bool) ($formData['is_self_pickup'] ?? $this->config['is_self_pickup'] ?? true),
            'orderNumber' => (string) ($this->config['order_number_prefix'] ?? '') . (string) $orderObj->id,
            'destinationId' => (int) ($this->config['destination_id'] ?? 4),
            'parcels' => [
                $this->buildParcelPayload($orderObj, $parcelSizeId, $internalBarcode, $isCod, $formData),
            ],
        ];

        $lastError = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep(self::RETRY_DELAY_MS * 1000);
                Log::info('Tinex retry attempt ' . ($attempt + 1) . ' for order #' . ($orderObj->id ?? '?'));
            }

            try {
                $response = $this->guzzle->post($this->baseUrl . '/order/orders/Create-and-finalize', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->token,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => $payload,
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                // Check if the API returned success=false with an error
                if (is_array($result) && isset($result['success']) && false === $result['success']) {
                    $errorMsg = $result['error']['message'] ?? 'خطای ناشناخته از سمت تینکس';
                    $lastError = $errorMsg;

                    if ($attempt < self::MAX_RETRIES) {
                        continue;
                    }

                    return [
                        'success' => false,
                        'error' => ['message' => $errorMsg],
                    ];
                }

                return is_array($result) ? $result : ['raw' => (string) $response->getBody()];
            } catch (Throwable $e) {
                $errorMsg = $e->getMessage();

                // Try to extract Persian error message from API response
                if ($e instanceof GuzzleException && $e->getResponse()) {
                    $responseBody = (string) $e->getResponse()->getBody();
                    $decoded = json_decode($responseBody, true);
                    if (isset($decoded['error']['message'])) {
                        $errorMsg = $decoded['error']['message'];
                    }
                }

                $lastError = $errorMsg;
                Log::warning('Tinex submit attempt ' . ($attempt + 1) . ' failed: ' . $errorMsg);

                if ($attempt >= self::MAX_RETRIES) {
                    break;
                }
            }
        }

        Log::error('Tinex submit failed after ' . (self::MAX_RETRIES + 1) . ' attempts: ' . $lastError);

        return [
            'success' => false,
            'error' => ['message' => 'خطا در ثبت سفارش تینکس: ' . $lastError],
        ];
    }

    // endregion

    // region Auth

    /**
     * Authenticate with Next One and store the JWT token.
     */
    private function login(string $username, string $password): void
    {
        try {
            $response = $this->guzzle->post($this->baseUrl . '/Auth/auth/login', [
                'json' => [
                    'username' => $username,
                    'password' => $password,
                    'clientType' => 1,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents());

            $this->token = $body->data->access_token ?? null;

            $expiresIn = (int) ($body->data->expires_in ?? 3600);
            $this->tokenExpiresAt = $expiresIn > 0 ? time() + $expiresIn : null;
        } catch (Throwable $e) {
            $this->token = null;
            $this->tokenExpiresAt = null;
            Log::error('Tinex login failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Refresh token if missing or within 30 seconds of expiry.
     */
    private function ensureToken(): void
    {
        if ( ! $this->token || ($this->tokenExpiresAt && time() >= $this->tokenExpiresAt - 30)) {
            $username = (string) ($this->config['username'] ?? '');
            $password = (string) ($this->config['password'] ?? '');
            if ($username && $password) {
                $this->login($username, $password);
            }
        }
    }

    // endregion

    // region Helpers

    /**
     * Build a single parcel payload for the Next One API.
     *
     * @param  array<string,mixed>  $formData
     * @return array<string,mixed>
     */
    private function buildParcelPayload(
        object $orderObj,
        int $parcelSizeId,
        string $internalBarcode,
        bool $isCod,
        array $formData
    ): array {
        // Derive dimensions from Next One size table when not explicitly provided
        $dims = $this->getDimensionsBySizeId($parcelSizeId);

        // Extract data from address relationship if the order is an Eloquent model
        $addressLat = '';
        $addressLng = '';
        $addressPostalCode = '';
        $addressPlaque = '';
        $addressUnit = '';

        // Check if order has an 'address' relationship (Eloquent model)
        if (is_object($orderObj) && method_exists($orderObj, 'address') && isset($orderObj->address)) {
            $address = $orderObj->address;
            $addressLat = (string) ($address->latitude ?? '');
            $addressLng = (string) ($address->longitude ?? '');
            $addressPostalCode = (string) ($address->postal_code ?? '');
            $addressPlaque = (string) ($address->plaque ?? '');
            $addressUnit = (string) ($address->unit ?? '');
        }

        // Resolve phone number: check mobile first, then fallback to address phone, then form data
        $phone = (string) ($formData['receiver_mobile'] ?? $orderObj->mobile ?? '');
        if (empty($phone) && is_object($orderObj) && method_exists($orderObj, 'address') && isset($orderObj->address)) {
            $phone = (string) ($orderObj->address->phone ?? '');
        }

        // Resolve required fields: form data > order model > address relation
        $lastName = (string) ($formData['receiver_last_name'] ?? $orderObj->shipping_last_name ?? '');
        $plaque = (string) ($formData['destination_plaque'] ?? $orderObj->shipping_plate_no ?? $addressPlaque);
        $unit = (string) ($formData['destination_unit'] ?? $orderObj->shipping_unit_no ?? $addressUnit);

        // Sanitize postal code: Tinex API fails on codes longer than 10 digits
        $postalCode = (string) ($orderObj->shipping_postal_code ?? $addressPostalCode);
        $postalCode = preg_replace('/[^0-9]/', '', $postalCode);
        $postalCode = mb_strlen($postalCode) > 10 ? mb_substr($postalCode, 0, 10) : $postalCode;

        return [
            'parcelValue' => (int) ($orderObj->grand_total ?? 0),
            'parcelSizeId' => $parcelSizeId,
            'weight' => (float) ($orderObj->shipping_weight ?? 1.0),
            'codAmount' => $isCod ? (int) ($orderObj->grand_total ?? 0) : 0,
            'receiverName' => (string) ($orderObj->shipping_first_name ?? ''),
            'receiverLastName' => $lastName,
            'receiverPhoneNumber' => $phone,
            'destinationLat' => (string) ($orderObj->shipping_lat ?? $addressLat),
            'destinationLng' => (string) ($orderObj->shipping_lng ?? $addressLng),
            'destinationAddress' => (string) ($orderObj->shipping_address ?? ''),
            'destinationPostalCode' => $postalCode,
            'destinationPlaque' => $plaque,
            'destinationUnit' => $unit,
            'description' => 'Order #' . (string) $orderObj->id,
            'parcelNumber' => $internalBarcode,
            'height' => (int) ($formData['height'] ?? $dims['height']),
            'length' => (int) ($formData['length'] ?? $dims['length']),
            'width' => (int) ($formData['width'] ?? $dims['width']),
            'region' => (string) ($formData['region'] ?? '1'),
            'deliveryCode' => (string) ($formData['delivery_code'] ?? ''),
        ];
    }

    /**
     * Get dimensions (width, height, length) by Next One parcelSizeId.
     *
     * @return array{width: int, height: int, length: int}
     */
    private function getDimensionsBySizeId(int $sizeId): array
    {
        return match ($sizeId) {
            1 => ['width' => 10, 'height' => 10, 'length' => 15],
            2 => ['width' => 15, 'height' => 10, 'length' => 20],
            3 => ['width' => 20, 'height' => 15, 'length' => 20],
            4 => ['width' => 20, 'height' => 20, 'length' => 30],
            5 => ['width' => 25, 'height' => 20, 'length' => 35],
            6 => ['width' => 25, 'height' => 20, 'length' => 45],
            7 => ['width' => 30, 'height' => 25, 'length' => 40],
            8 => ['width' => 40, 'height' => 30, 'length' => 45],
            9 => ['width' => 45, 'height' => 35, 'length' => 55],
            10 => ['width' => 0, 'height' => 0, 'length' => 0],
            default => ['width' => 20, 'height' => 15, 'length' => 20], // fallback to size 3
        };
    }

    // endregion
}
