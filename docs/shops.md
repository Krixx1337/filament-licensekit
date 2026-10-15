# Create license keys

LicenseKit does not take payment. Your store, or Shopify, Stripe, WooCommerce, or a Telegram reseller, asks for keys after the sale. The same order id returns the same keys.

You can also create license keys from the admin with no API. On a license plan, use **Create keys**. Set a quantity, a reference, and an optional label. Export downloads `license-keys-{id}.txt`, one serial per line. **Revoke unclaimed keys** cancels the unsold keys in that batch. Claimed licenses stay.

## From the same Laravel application

Use `IssueLicenses` from a trusted job or service after verifying payment. No Filament session or management token is needed. Configure a local identity in `config/licensekit.php`:

```php
'local_integrations' => [
    'store' => [
        'enabled' => true,
        'all_products' => false,
        'allowed_product_ids' => [1],
        'maximum_quantity' => 10,
        'disclose_serials' => true,
    ],
],
```

```php
use LicenseKit\Core\Actions\IssueLicenses;

$result = app(IssueLicenses::class)->issue(
    integration: 'store',
    planId: $purchase->license_plan_id,
    quantity: 1,
    reference: 'purchase:'.$purchase->id.':line:1',
    discloseSerials: true,
);

// Persist $result->batchId and $result->keyIds with your purchase fulfillment.
// Deliver $result->serials after the outer database transaction commits.
```

`IssuanceResult` contains `batchId`, ordered `keyIds`, `replayed`, and nullable `serials`. Serials are omitted unless explicitly requested and permitted. Do not log a result containing serials. The integration name is chosen by trusted application code, never customer request input. Names contain lowercase ASCII letters, numbers, underscores, and hyphens, start with a letter or number, and are at most 64 characters.

Identity is `local:{name}`. Keep the name stable across releases. Same identity/reference plus the same plan and quantity returns the original batch, including its original duration snapshot. Changing plan or quantity returns `batch_conflict`, status 409. Label is descriptive and does not change retry identity. Authorization, product scope, quantity caps, and disclosure permission are checked again on retries. A disabled integration cannot retrieve prior issuance; an authorized retry can retrieve an existing batch after its plan is disabled.

`integration_forbidden`, status 403, covers an unknown/disabled identity, product outside its scope, invalid quantity, or unauthorized serial disclosure. The package-wide `maximum_batch_size` also applies. Common issuance errors include `plan_unavailable`, `invalid_reference`, `invalid_batch_label`, `invalid_key_normalizer`, and `licensing_unavailable`. PHP errors are `LicensingException` instances with `error` and `status`; these operations do not return HTTP responses.

Issuance joins an outer transaction. If purchase persistence rolls back, its batch, keys, and licensing audit roll back too. Email failures after commit must retry delivery using the same batch, rather than create a new reference. Guaranteed delivery needs a host outbox/reconciliation workflow.

Map commerce products to licensing products and plans in your application. Use immutable/versioned plans for accepted purchases: delayed issuance must use the purchased terms, not today's edited plan. The package does not verify payment, deduplicate provider events, or track orders/refunds. Purchase references are opaque and limited to 128 bytes. Do not rename an established issuer without migrating its fulfillment mappings.

Admin PHP operations remain available through `CreateBatch::createFor($admin, ...)`. Catalog maintenance outside a panel uses `Core\Actions\CatalogWrites` with an explicit actor and Laravel policy checks. `Filament\CatalogWrites` is the panel actor adapter. `CreateBatch::create()` is an internal engine, not a supported authorization boundary.

## From an external store or reseller

Set `licensekit.management.enabled` to `true`, then rebuild the config and route caches. The default prefix is `/api/licensekit/v1/manage`. HTTP stays off until you enable it.

In the admin, create an API client. You get a token once. It expires in one year. Grant `licenses:create` and `keys:read`, pick the product, and set a quantity cap. The default cap is 100. Selected products do not include products you add later. All-products does.

Do not put this token in a desktop app or a browser.

```sh
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $LICENSEKIT_MANAGEMENT_TOKEN" \
  -H "Content-Type: application/json" \
  --data "{\"plan_id\":1,\"quantity\":1,\"reference\":\"order-123\",\"label\":\"Store fulfillment\"}" \
  "$LICENSEKIT_API_ROOT/batches"
```

`LICENSEKIT_API_ROOT` is your prefix, for example `https://example.com/api/licensekit/v1/manage`. A new batch returns 201. The same `reference` from the same client returns 200 and the original keys. A different plan or quantity on that reference returns 409 `batch_conflict`.

Look up ids first if you need them:

```sh
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $LICENSEKIT_MANAGEMENT_TOKEN" \
  "$LICENSEKIT_API_ROOT/products?search=your-product-slug"
```

Plans are `$LICENSEKIT_API_ROOT/plans?product_id=1`. Listing products and plans needs no extra permission. Reading another client's batches needs `batches:read-all`.

| Permission | What it allows |
| --- | --- |
| `licenses:create` | Create a batch |
| `keys:read` | Include the serials in the response |
| `batches:read-all` | See batches created by someone else |

Without `keys:read`, creation still works and the response has no serials.

From PHP on the server, an admin call checks the `createKeys` ability:

```php
use LicenseKit\Core\Actions\CreateBatch;

$batch = app(CreateBatch::class)->createFor($admin, $plan, 1, $orderId, 'Store fulfillment');
```

A shop webhook that already holds a management token uses `ManagementOperations::create($bearer, $planId, $quantity, $reference, $label)`.

Keys do not start the clock until someone [claims](client.md#claim-a-key) them. LicenseKit does not check that Stripe or Shopify was actually paid. Your store does that, then calls this.
