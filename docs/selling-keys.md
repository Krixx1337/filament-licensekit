# Selling keys

Keys are vouchers for account access. Your store owns orders, payments and delivery. There is no account-free activation mode.

## Manual sales and refunds

Open **Keys → Generate keys**. Choose the product and plan, how many keys you need and an optional label (for example the reseller's name or "October giveaway"). Copy the keys or download them as a `.txt` file. You can also select keys in the list and choose **Download selected**.

**Key batches** shows each batch with its progress, such as "12 of 50 redeemed". From a batch's menu you can download its unused keys, or cancel them after a leak or a reseller dispute. Customers who already redeemed keep their time. To take access away from one customer after a refund, open their license and choose **Manage → Cancel license**. A cancelled license gets no paid or free access and cannot be renewed until you restore it.

## Store and reseller connections

Open **Setup → Store connections → New store connection**. Choose which products it may sell, the most keys it can request at once, and whether it is active. A connection can create keys for its products and read only the batches it made.

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
