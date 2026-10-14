# Mint keys

LicenseKit does not take payment. Your store, or Shopify, Stripe, WooCommerce, or a Telegram reseller, asks for keys after the sale. The same order id returns the same keys.

You can also mint from the admin with no API. On a license plan, use **Create keys**. Set a quantity, a reference, and an optional label. Export downloads `license-keys-{id}.txt`, one serial per line. **Revoke unclaimed keys** cancels the unsold keys in that batch. Claimed licenses stay.

## From your store

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
| `licenses:create` | Mint a batch |
| `keys:read` | Include the serials in the response |
| `batches:read-all` | See batches minted by someone else |

Without `keys:read`, creation still works and the response has no serials.

From PHP on the server, an admin call checks the `createKeys` ability:

```php
use LicenseKit\Core\Actions\CreateBatch;

$batch = app(CreateBatch::class)->createFor($admin, $plan, 1, $orderId, 'Store fulfillment');
```

A shop webhook that already holds a management token uses `ManagementOperations::create($bearer, $planId, $quantity, $reference, $label)`.

Keys do not start the clock until someone [claims](client.md#claim-a-key) them. LicenseKit does not check that Stripe or Shopify was actually paid. Your store does that, then calls this.
