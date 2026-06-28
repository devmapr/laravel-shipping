# Laravel Shipping Manager

> Pluggable shipping driver manager for Laravel.

## Drivers

| Driver | Provider | Status |
|--------|----------|--------|
| **Alonomic** (Alopeyk) | `Planx\Shipping\Drivers\AlonomicDriver` | ✅ Active |
| **Tinex** (Next One) | `Planx\Shipping\Drivers\TinexDriver` | ✅ Active |
| **SnappBox** | `Planx\Shipping\Drivers\SnappBoxDriver` | ✅ Active (Webhook) |
| **Forward** | `Planx\Shipping\Drivers\ForwardDriver` | ✅ Active |

## Installation

```bash
composer require planx/laravel-shipping
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=shipping-config
```

Then set your environment variables in `.env`:

```env
# Default driver
SHIPPING_DRIVER=snappbox

# SnappBox
SNAPPBOX_ACTIVE=true
SNAPPBOX_API_BASE_URL=https://api.snappbox.com
SNAPPBOX_WEBHOOK_TOKEN=your-webhook-token

# Tinex (Next One)
TINEX_ACTIVE=true
TINEX_USERNAME=your-username
TINEX_PASSWORD=your-password
TINEX_ORIGIN_CONTACT_ID=28
TINEX_DESTINATION_ID=4
TINEX_DEFAULT_PARCEL_SIZE_ID=3
TINEX_BARCODE_PREFIX=TINX_
TINEX_ORDER_NUMBER_PREFIX=ORD-

# Alonomic (Alopeyk)
ALONOMIC_ACTIVE=true
ALONOMIC_EMAIL=your-email
ALONOMIC_PASSWORD=your-password
```

## Usage

```php
use Planx\Shipping\Facades\Shipping;

// Get a driver
$shipping = Shipping::driver('tinex');

// Submit an order
$result = $shipping->submit($order, [
    'parcel_size_id' => 3,
    'is_self_pickup' => true,
]);
```

### SnappBox Webhooks

SnappBox sends real-time status updates via webhook. Add this route to your `routes/api.php`:

```php
Route::post('snappboxwebhooks/{webhookType}', [SnappBoxWebhookController::class, 'handle'])
    ->middleware([SnappBoxWebhookAuthMiddleware::class]);
```

Supported webhook types:

| Webhook | Event |
|---------|-------|
| `accepted` | Biker allocated to order |
| `arrived` | Biker arrived at pickup |
| `pickedup` | Package picked up |
| `arrivedAtDropOff` | Biker arrived at drop-off |
| `delivered` | Package delivered |
| `canceled` | Order cancelled |
| `canceled-by-system` | System cancelled (no biker) |
| `canceled-allocation` | Biker cancelled allocation |
| `invoice-update` | Invoice status updated |
| `failed-deliver` | Delivery failed |
| `failed-deliver-add-return` | Return added after failure |
| `failed-deliver-return-to-source` | Package returned to source |

## Development

```bash
composer install
```

### Adding a new driver

1. Create a class in `src/Drivers/` implementing `Planx\Shipping\Contracts\ShippingDriver`
2. Add the driver config to `config/shipping.php`
3. Use it via `Shipping::driver('your-driver')`

## License

MIT
