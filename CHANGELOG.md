# Changelog

## v1.1.0 — 2025-06-29

### Added
- **SnappBoxDriver** — Full outgoing B2B API integration:
  - `POST /v1/orders` — Create order with auto-built payload from Eloquent models
  - `GET /v1/orders/:orderId` — Get order by ID
  - `PUT /v1/orders/:orderId` — Update order
  - `DELETE /v1/orders/:orderId` — Cancel order
  - `POST /v1/pricing` — Get delivery pricing estimate
  - `GET /v1/orders/delivery-categories` — Get available delivery categories by location
  - `GET /v1/wallets` — Get wallet balance
  - `GET /v1/orders/references/:refId` — Get order by customer reference ID
  - `GET /v1/orders/list` — List orders with filtering
  - `GET /v1/orders/:orderId/current-location` — Track driver location
  - `GET /v1/orders/:orderId/events` — Get order event history
- Token-based authentication with auto-refresh (login, login-by-token, refresh-token)
- Retry logic with configurable attempts and delay
- `submit(order, formData)` helper for Filament admin panel integration
- New config keys: `api_token`, `username`, `password`, `origin_*`, `delivery_category`, `city`, `ref_id_prefix`

### Changed
- `SnappBoxDriver` contract methods now perform real API calls instead of returning stubs
- `ensureToken()` throws `RuntimeException` when no credentials are configured

## v1.0.0 — 2025-06-28

### Added
- Initial release
- `ShippingDriver` interface with 6 contract methods
- `ShippingManager` for driver resolution via config
- `Shipping` facade for easy access
- **AlonomicDriver** — Alopeyk courier API integration
- **TinexDriver** — Next One (Tinex) courier API with retry logic
- **SnappBoxDriver** — SnappBox webhook handling with DB transactions
- **ForwardDriver** — Forward courier placeholder
- Laravel auto-discovery via service provider
- Config publishable via `vendor:publish`
