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

`integration_forbidden`, status 403, covers an unknown/disabled identity, product outside its scope, invalid quantity, or unauthorized serial disclosure. The package-wide `maximum_batch_size` also applies. Common issuance errors include `plan_unavailable`, `invalid_reference`, `invalid_batch_label`, and `licensing_unavailable`. PHP errors are `LicensingException` instances with `error` and `status`; these operations do not return HTTP responses.

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

Plans are `$LICENSEKIT_API_ROOT/plans?product_id=1`. Listing products and plans needs no extra permission. Clients can read batches they issued and batches attributed to their owner, within their product scope. Other batches need `batches:read-all`.

| Permission | What it allows |
| --- | --- |
| `licenses:create` | Create a batch |
| `keys:read` | Include the serials in the response |
| `batches:read-all` | See all batches within allowed products |

Without `keys:read`, creation still works and the response has no serials.

From PHP on the server, an admin call checks the `createKeys` ability:

```php
use LicenseKit\Core\Actions\CreateBatch;

$batch = app(CreateBatch::class)->createFor($admin, $plan, 1, $orderId, 'Store fulfillment');
```

A shop webhook that already holds a management token uses `ManagementOperations::create($bearer, $planId, $quantity, $reference, $label)`.

Keys do not start the clock until someone [claims](client.md#claim-a-key) them. LicenseKit does not check that Stripe or Shopify was actually paid. Your store does that, then calls this.

## Registered resellers

Ask a reseller to register on your website, then select that account as the optional owner when creating their API client. Internal storefront integrations can remain ownerless. No reseller profile table or special user role is required.

Tokens still belong to the API client. The owner's availability is checked on every management operation, including reads and retries. Suspension, required-but-missing email verification, a missing account, or soft deletion blocks access with `invalid_token`, HTTP 401. Ownership gives no admin-panel access. Clients belonging to the same user share access to that reseller's attributed batches, subject to each client's product scope. Issuance reference namespaces remain separate per client. Retire attached clients before hard-deleting their owner. See [account ownership](installation.md#reseller-account-ownership).

API batches record `api_client_id` and the owner's `reseller_user_id`. Admin batches record `created_by_user_id` and an optional reseller selected in **Create keys**. This attributes distribution; it does not claim keys for the reseller. The final buyer still claims the key on your website.

Trusted local issuance can also attribute a registered reseller:

```php
$result = app(IssueLicenses::class)->issue(
    integration: 'store',
    planId: $purchase->license_plan_id,
    quantity: 10,
    reference: 'purchase:'.$purchase->id,
    reseller: $reseller,
);
```

Admin PHP issuance accepts the same optional argument:

```php
$batch = app(CreateBatch::class)->createFor(
    $admin, $plan, 10, $orderId, reseller: $reseller,
);
```

For new admin/local batches, the reseller must be an existing available configured host user or issuance throws `invalid_reseller`, HTTP 422. The host decides who qualifies as a reseller. No product entitlement is required. Resolve reseller identity through trusted host code rather than passing arbitrary customer-selected attribution.

Attribution is fixed when a batch is created. Repeating the same issuer/reference returns the original batch and attribution even when a different reseller argument is supplied. Existing plan/quantity conflict checks and caller authorization still apply. `issuer` remains the stable retry namespace, including `api:{id}`, `user:{id}`, `local:{name}`, and historical import identities. Client renames and token rotation do not change it.

The admin can filter batches by client or reseller. Deleted-user relationships become null; batch creation and client retirement audits retain original attribution IDs. Retirement never deletes batches or revokes their keys.

`maximum_quantity` is a per-request cap. It is not a cumulative allowance or proof of payment: repeated authorized requests can issue more keys. Only give direct management issuance access to trusted resellers under your payment agreement. If each purchase must fund issuance, the host verifies payment or debits its own balance before calling `IssueLicenses`. LicenseKit does not provide a wallet or prepaid quota.

**Unclaimed keys may already have been sold.** Buyers can pay before activating. Review affected batches before using **Revoke unclaimed keys**; suspending or retiring a reseller does not perform this action automatically.

## Reseller pages and bot downloads

For a reseller who buys keys through your website, verify payment in host code and issue a batch with `reseller: $reseller`. Their panel or bot can then read that batch regardless of whether staff, a local integration, or another owned client created it. Give ordinary reseller clients only `keys:read` and selected products. Leave `licenses:create` and `batches:read-all` unchecked.

Host pages can use the authenticated account without an admin session or management token:

```php
use LicenseKit\Core\Actions\ResellerInventory;

$inventory = app(ResellerInventory::class);
$reseller = $request->user(); // Host-authenticated configured user, never a submitted user ID.
$batches = $inventory->batches($reseller, perPage: 25, page: 1, planId: null);
$batch = $inventory->batch($reseller, $batchId);
$keys = $inventory->keys($reseller, $batchId, perPage: 100, page: 1, status: 'unclaimed');
```

Every call reloads the account and checks availability. Batch/key reads reject another reseller's batch with not-found. Batch results eagerly load `plan.product` and include `total_count`, `claimed_count`, `unclaimed_count`, and `revoked_count`. They remain server-side models; choose fields explicitly when building a host HTTP response. Ownership is sufficient for these reads and does not grant admin powers. Catalog disablement does not hide purchased batches.

The equivalent bot requests use existing endpoints:

```text
GET /batches?per_page=25&page=1
GET /batches/88
GET /batches/88/keys?status=unclaimed&per_page=100&page=1
```

Batch GET responses include the same counts. Key lists return `data`, `links`, and `meta`; default page size is 25, maximum 100. PHP returns a `LengthAwarePaginator` with the same explicit key fields. Follow every page when downloading a whole batch. Each key contains `id`, `key`, saved `duration_days`, and nullable UTC `claimed_at`/`revoked_at`. Private notes and buyer identities are excluded. Filters are `all` by default, `unclaimed`, `claimed`, and `revoked`. Unclaimed means neither claimed nor revoked. Claimed/revoked filters inspect those timestamps independently. Counts describe current key state, not active entitlements or sales; original `quantity` remains creation history.

To build a text download, authenticate the host request, read authorized key pages, and emit each row's `key` as a line with `Cache-Control: private, no-store`. Repeating a read/download does not consume keys or record delivery. The existing Filament exports retain their one-click behavior.

A reseller imports these serials into their store or bot, which tracks sales and prevents duplicate delivery. Fetching the first unclaimed key for each order is unsafe: sold keys can remain unclaimed, and concurrent reads can return the same key. LicenseKit does not reserve, dispatch, or count sold keys. Your sales records own those answers.
