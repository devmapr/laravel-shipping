<p align="center">
    <h1 align="center">Laravel Shipping Manager</h1>
    <p align="center">A pluggable, multi-driver shipping manager for Laravel.</p>
</p>

<p align="center">
    <a href="https://packagist.org/packages/devmapr/laravel-shipping"><img src="https://img.shields.io/packagist/v/devmapr/laravel-shipping" alt="Latest Version"></a>
    <a href="https://packagist.org/packages/devmapr/laravel-shipping"><img src="https://img.shields.io/packagist/dt/devmapr/laravel-shipping" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/devmapr/laravel-shipping"><img src="https://img.shields.io/packagist/l/devmapr/laravel-shipping" alt="License"></a>
    <a href="https://github.com/devmapr/laravel-shipping"><img src="https://img.shields.io/github/stars/devmapr/laravel-shipping" alt="GitHub Stars"></a>
</p>

---

## Features

- 🚚 **Multi-driver** — Switch between courier providers with zero code changes
- 🔌 **Pluggable** — Add custom drivers via a simple interface
- 📦 **Laravel Auto-Discovery** — Service provider and facade registered automatically
- 🕸 **Webhook Support** — Receive real-time delivery status updates (SnappBox)
- 🧪 **Easy to test** — Designed for testability and SOLID principles

## Supported Drivers

| Driver | Provider | Type | Status |
|--------|----------|------|--------|
| **Alonomic** | [Alopeyk](https://alopeyk.com) | Courier API | ✅ Active |
| **Tinex** | [Next One](https://tinextco.com) | Courier API | ✅ Active |
| **SnappBox** | SnappBox | Courier + Webhook | ✅ Active |
| **Forward** | Forward | Courier API | ✅ Active |

---

## Installation

```bash
composer require devmapr/laravel-shipping
```

> **Laravel Auto-Discovery** automatically registers the service provider and facade. No manual steps needed.

## Configuration

### 1. Publish the config file

```bash
php artisan vendor:publish --tag=shipping-config
```

### 2. Set environment variables

Add the following to your `.env` file based on the drivers you need:

```env
# ─── Default Driver ───────────────────────────────────
SHIPPING_DRIVER=tinex

# ─── Alonomic (Alopeyk) ───────────────────────────────
ALONOMIC_ACTIVE=true
ALONOMIC_EMAIL=your@email.com
ALONOMIC_PASSWORD=your-password

# ─── Tinex (Next One) ─────────────────────────────────
TINEX_ACTIVE=true
TINEX_USERNAME=your-username
TINEX_PASSWORD=your-password
TINEX_ORIGIN_CONTACT_ID=28
TINEX_DESTINATION_ID=4
TINEX_DEFAULT_PARCEL_SIZE_ID=3
TINEX_BARCODE_PREFIX=TINX_
TINEX_ORDER_NUMBER_PREFIX=ORD-

# ─── SnappBox ─────────────────────────────────────────
SNAPPBOX_ACTIVE=true
SNAPPBOX_API_BASE_URL=https://api.snappbox.com
SNAPPBOX_WEBHOOK_TOKEN=your-webhook-token

# ─── Forward ──────────────────────────────────────────
FORWARD_ACTIVE=true
FORWARD_USERNAME=your-username
FORWARD_PASSWORD=your-password
FORWARD_SENDER_PHONE=09120000000
FORWARD_SENDER_ADDRESS=Tehran, ...
FORWARD_SENDER_POSTAL_CODE=1234567890
```

---

## Usage

### Basic usage

```php
use Planx\Shipping\Facades\Shipping;

// Resolve a driver by name
$shipping = Shipping::driver('tinex');

// Submit an order (driver-specific payload)
$result = $shipping->submit($order, [
    'parcel_size_id' => 3,
    'is_self_pickup' => true,
]);
```

### Tinex (Next One) — Parcel Size Options

```php
$sizes = \Planx\Shipping\Drivers\TinexDriver::getSizeOptions();
// [1 => 'بسته سایز 1 (10×15×10)', 2 => 'بسته سایز 2 (15×20×10)', ...]
```

### SnappBox — Webhook Handling

SnappBox sends real-time delivery status updates to your server. All webhook logic is handled by `SnappBoxDriver::handleWebhook()`.

**Create a simple controller in your app:**

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Planx\Shipping\Drivers\SnappBoxDriver;

class SnappBoxWebhookController extends Controller
{
    public function __construct(
        private readonly SnappBoxDriver $driver,
    ) {}

    public function __invoke(Request $request, string $webhookType): JsonResponse
    {
        $payload = $request->json()->all();

        if ( ! is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid JSON'], 400);
        }

        try {
            $result = $this->driver->handleWebhook($webhookType, $payload);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Internal error'], 200);
        }
    }
}
```

**Register the route:**

```php
// routes/api.php
Route::post('snappboxwebhooks/{webhookType}', SnappBoxWebhookController::class);
```

Protected by a Bearer token set in your `.env`:

```env
SNAPPBOX_WEBHOOK_TOKEN=your-secret-token
```

**Supported webhook types:**

| Type | Event |
|------|-------|
| `accepted` | Biker assigned to the order |
| `arrived` | Biker arrived at pickup location |
| `pickedup` | Package picked up by biker |
| `arrivedAtDropOff` | Biker arrived at drop-off location |
| `delivered` | Package successfully delivered |
| `canceled` | Order cancelled by user |
| `canceled-by-system` | Order cancelled by system (no biker accepted) |
| `canceled-allocation` | Biker cancelled the allocation |
| `invoice-update` | Invoice status changed |
| `failed-deliver` | Delivery attempt failed |
| `failed-deliver-add-return` | Return label created after failed delivery |
| `failed-deliver-return-to-source` | Package returned to sender |

---

## Creating a Custom Driver

1. **Implement the interface**

```php
use Planx\Shipping\Contracts\ShippingDriver;

class MyCourierDriver implements ShippingDriver
{
    public function createParcel(array $payload): array { /* ... */ }
    public function updateParcel(int|string $id, array $payload): array { /* ... */ }
    public function getParcel(int|string $id): array { /* ... */ }
    public function deleteParcel(int|string $id): array { /* ... */ }
    public function getDays(): array { /* ... */ }
    public function getDaysIndex(): array { /* ... */ }
}
```

2. **Register in `config/shipping.php`**

```php
'drivers' => [
    'mycourier' => [
        'class' => \App\Shipping\MyCourierDriver::class,
        'config' => [
            'api_key' => env('MYCOURIER_API_KEY'),
        ],
    ],
],
```

3. **Use it**

```php
Shipping::driver('mycourier')->createParcel([...]);
```

---

## Architecture

```
src/
├── Contracts/
│   └── ShippingDriver.php          # Driver interface
├── Drivers/
│   ├── AlonomicDriver.php          # Alopeyk integration
│   ├── ForwardDriver.php           # Forward integration
│   ├── SnappBoxDriver.php          # SnappBox + webhooks
│   └── TinexDriver.php             # Next One (Tinex) integration
├── Facades/
│   └── Shipping.php                # Facade for ShippingManager
├── Providers/
│   └── ShippingServiceProvider.php # Auto-discovery service provider
└── ShippingManager.php             # Driver resolution & management
```

---

## Requirements

- PHP `>= 8.2`
- Laravel `^10.0 | ^11.0 | ^12.0`

---

## Contributing

Contributions are welcome! Please open an issue or submit a PR on [GitHub](https://github.com/devmapr/laravel-shipping).

## License

This package is open-source software licensed under the MIT license.
