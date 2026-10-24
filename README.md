# mypinacle/meta-sdk

Shared Meta commerce code for MyPinacle apps (Ecom, Inventory). Framework glue stays in the apps; this package holds the rules.

- `Catalog\ContentId`: the one place catalog / pixel content ids are built (`{product}`, `{product}-v{variant}`, `-c{color}`).
- `Catalog\CatalogItem` + `Catalog\Format`: Meta PRODUCT_ITEM field rules (price `350.00 BDT`, availability, title 100 / description 5,000 chars, sale price only when lower, `internal_label`).
- `Catalog\BatchPlanner`: payload-hash diff, UPDATE/DELETE requests, 3,000-record chunks.
- `Catalog\CatalogBatchClient`: `POST /{catalog}/items_batch`, `GET /{catalog}/check_batch_request_status`, error classification (80014 rate limit, 190 reconnect).

Graph API version comes from `MyPinacle\Tracking\Graph` (mypinacle/tracking-sdk), so every Meta call uses one version.

Tests: `composer install && vendor/bin/phpunit`.
## Messaging (v1.2.0)

`MyPinacle\Meta\Messaging\SendClient` (Page access token): `send`/`sendText` (Send API, RESPONSE or MESSAGE_TAG), `senderAction`, `privateReply` (comment_id), `replyToComment` and `hideComment` (Facebook / Instagram), `subscribeApp`/`subscribedApps`, `page`, `pages` (me/accounts), `setMessengerProfile`, `profile`.

Errors: `MyPinacle\Meta\GraphApiException` (shared; `CatalogApiException` now extends it) with `isRateLimit`, `isTransient`, `needsReconnect`, `isOutsideWindow`, `isRecipientUnavailable`.

