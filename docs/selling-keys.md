# Selling keys

Keys are vouchers for account access. Your store owns orders, payments and delivery. There is no account-free activation mode.

## Manual sales and refunds

Open **Keys → Generate keys**. Choose the product and plan, how many keys you need and an optional label (for example the reseller's name or "October giveaway"). Copy the keys or download them as a `.txt` file. You can also select keys in the list and choose **Download selected**.

**Key batches** shows each batch with its progress, such as "12 of 50 redeemed". From a batch's menu you can download its unused keys, or cancel them after a leak or a reseller dispute. Customers who already redeemed keep their time. To take access away from one customer after a refund, open their license and choose **Manage → Cancel license**. A cancelled license gets no paid or free access and cannot be renewed until you restore it.

## Manual Telegram reseller

Create a normal website account for the reseller. In **Keys > Generate keys**, choose that account under **Reseller (optional)**. Download the batch as a `.txt` file and send it through your usual channel. No API token is needed.

Give the account access to your reseller page to see purchases and download unused keys. **Key batches** filters by reseller, and the license page shows who sold each redeemed key.

## Bot reseller

Open **Setup > Store connections > New store connection**. Choose **Owner (optional)**, allowed products and a request cap. Enable `store_api_enabled`, then copy the token from the one-time modal. Only its SHA-256 hash is stored. Regenerating invalidates the old token immediately.

```sh
curl -X POST 'https://your-site.test/api/licensekit/store/batches' \
  -H 'Authorization: Bearer YOUR_CONNECTION_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"plan_id":1,"quantity":10,"reference":"order-123","label":"October sale"}'
```

New batches belong to the connection owner automatically. The bot can read its own batches and all batches assigned to its owner, including manual purchases. Other resellers' batches stay private. With `user_suspended_column` configured, suspending the owner's website account blocks the token on its next request with `user_unavailable` and HTTP 403. Email verification does not affect bots. Deleting the owner's account turns their connections off. An ownerless connection reads only its own batches.

Use a stable reference per order. Identical retries return the original batch and keys. Changing the plan, quantity, custom duration or reseller returns `batch_conflict` with HTTP 409. Each connection has its own reference namespace. Catalog reads respect allowed products; past batch reads remain available after product permissions change.

| Method | Endpoint under `/api/licensekit/store` |
|---|---|
| GET | `/products`, `/plans` |
| POST | `/batches` |
| GET | `/batches`, `/batches/{batch}`, `/batches/{batch}/keys` |

Lists paginate, default 25, maximum 100. Key statuses are `all`, `unused`, `redeemed`, `cancelled`. Quantity is bounded by the connection cap and `max_batch_size`. Disable the connection to stop its API access.

## Your own wholesale page

After your host app accepts payment, issue keys for the logged-in user. Payments, wallets, pricing and route access stay in your app.

```php
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\ResellerBatches;
use LicenseKit\Core\Data\IssueContext;

$batch = app(Keys::class)->issue($plan, $qty, new IssueContext(
    source: 'app', reference: $orderId, reseller: auth()->user(),
));

$batches = app(ResellerBatches::class)->paginate($user);
$keys = app(ResellerBatches::class)->unusedKeys($user, $batch);
```

`paginate()` includes total, redeemed, unused and cancelled counts. `unusedKeys()` returns a list of serials and responds with 404 for another reseller's batch. Suspended users cannot read or export inventory.

Place this component on a host page protected by your authentication middleware:

```blade
<livewire:licensekit.reseller-batches />
```

It uses the configured LicenseKit guard and shows purchases with a `.txt` download for unused keys. Publish and style `resources/views/vendor/licensekit/reseller-batches.blade.php` with `php artisan vendor:publish --tag=licensekit-views`. No reseller role or panel access is required.
