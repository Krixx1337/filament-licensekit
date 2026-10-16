# Selling keys

Keys are vouchers for account access. Your store owns orders, payments and delivery. There is no account-free activation mode.

## Manual sales and refunds

Use Keys / Generate keys. Pick product, plan and quantity; optional label identifies reseller or giveaway. Copy results or explicitly download `.txt`.

Key batches under Settings show unused, redeemed and cancelled counts. Download unused keys or cancel them after reseller dispute. Customers who redeemed keep their time. Cancel their license separately after refund; cancellation blocks paid/free access and renewal until staff restore it.

## Store and reseller connections

Settings / Store & reseller connections offers allowed products, max keys per request and Active. Connection can issue allowed products and read only batches attributed to it. No reseller user or token scopes.

Copy token from one-time modal. Only SHA-256 hash is stored. Regenerate invalidates old token immediately. Disable connection to stop API access without changing keys/licenses.

Enable `store_api_enabled`. Default prefix is `/api/licensekit/store`.

```sh
curl -X POST 'https://your-site.test/api/licensekit/store/batches' \
  -H 'Authorization: Bearer YOUR_CONNECTION_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"plan_id":1,"quantity":10,"reference":"order-123","label":"October sale"}'
```

Use stable reference per order. Identical retries return original batch and complete keys even after plan edits. Changed plan/quantity/custom duration returns 409. Connections have separate reference namespaces.

Catalog reads respect current allowed products. Past batch/key reads remain limited to connection's own batches even after product permissions change.

| Method | Endpoint under store prefix |
|---|---|
| GET | `/products`, `/plans` |
| POST | `/batches` |
| GET | `/batches`, `/batches/{batch}`, `/batches/{batch}/keys` |

Lists paginate, default 25, max 100. Key statuses are `all`, `unused`, `redeemed`, `cancelled`. Quantity is bounded by connection cap and package `max_batch_size`.

Store in same Laravel app can call `Keys::issue()` with stable `IssueContext::reference`. See [extending](extending.md). Trusted app calls need no token.
