# Changelog

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
