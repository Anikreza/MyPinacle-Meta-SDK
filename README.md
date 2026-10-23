# mypinacle/meta-sdk

Shared Meta commerce code for MyPinacle apps (Ecom, Inventory). Framework glue stays in the apps; this package holds the rules.

- `Catalog\ContentId`: the one place catalog / pixel content ids are built (`{product}`, `{product}-v{variant}`, `-c{color}`).
- `Catalog\CatalogItem` + `Catalog\Format`: Meta PRODUCT_ITEM field rules (price `350.00 BDT`, availability, title 100 / description 5,000 chars, sale price only when lower, `internal_label`).
- `Catalog\BatchPlanner`: payload-hash diff, UPDATE/DELETE requests, 3,000-record chunks.
- `Catalog\CatalogBatchClient`: `POST /{catalog}/items_batch`, `GET /{catalog}/check_batch_request_status`, error classification (80014 rate limit, 190 reconnect).

Graph API version comes from `MyPinacle\Tracking\Graph` (mypinacle/tracking-sdk), so every Meta call uses one version.

Tests: `composer install && vendor/bin/phpunit`.