# Selling keys

Keys are vouchers for account access. Your store owns orders, payments and delivery. There is no account-free activation mode.

Sell through several stores at once. Payments never touch LicenseKit, so if a payment processor closes one store, your licenses keep working.

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

## Stripe, Shopify, WooCommerce or any store webhook

When your site receives the store's paid-order webhook, issue a key and deliver it. Put the route in `routes/api.php` (no CSRF).

```php
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\LicensePlan;

Route::post('/webhooks/shop', function (Request $request) {
    // 1. Verify the store's signature. Each store documents its header; Shopify's looks like this.
    $expected = base64_encode(hash_hmac('sha256', $request->getContent(), config('services.shop.secret'), true));
    abort_unless(hash_equals($expected, (string) $request->header('X-Shopify-Hmac-Sha256')), 401);

    // 2. Map what they bought to a plan, for example by SKU.
    $plan = LicensePlan::findOrFail(config('services.shop.plans')[$request->input('line_items.0.sku')]);

    // 3. Issue. The order id makes webhook retries return the same key instead of a new one.
    $batch = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'app', reference: 'shop:'.$request->input('id'), label: 'Shop order'));

    // 4. Deliver it your way: email, order note or download page.
    Mail::to($request->input('email'))->send(new LicenseKeyMail($batch->keys[0]->key));

    return response()->noContent();
});
```

| Store | Webhook | Signature header | Order reference |
|---|---|---|---|
| Stripe | `checkout.session.completed` | `Stripe-Signature` (verify with `Stripe\Webhook::constructEvent`) | session `id` |
| Shopify | `orders/paid` | `X-Shopify-Hmac-Sha256` | order `id` |
| WooCommerce | Order updated, when status is `processing` or `completed` | `X-WC-Webhook-Signature` | order `id` |
| SellAuth | Dynamic delivery, set on the variant (see below) | `X-Signature` | invoice item `item.id` |
| SellApp | Dynamic webhook, set on the variant (see below) | `Signature` | order id from the body |

Prefix each store's references (`stripe:`, `shop:`) so they never collide. For refunds, cancel the unused key with `ManageKeys::revokeKey`, or the license if it was redeemed. Stores you cannot run code for, such as resellers' bots, use a store connection instead.

## SellAuth or SellApp dynamic delivery

Both stores can ask your server for the key at checkout and show your reply to the buyer. Set the variant's delivery type to dynamic and enter your route's URL. Keep your storefront; LicenseKit only issues the key.

SellAuth sends one `POST` per invoice item, signed with HMAC-SHA256 of the raw body in `X-Signature`. Find the secret under **Developers > Overview**. A `200` marks the item delivered, and each line of the body is one deliverable. ([SellAuth docs](https://docs.sellauth.com/developers/dynamic-delivery))

```php
Route::post('/webhooks/sellauth', function (Request $request) {
    // 1. Verify. SellAuth signs the raw body with your webhook secret.
    $expected = hash_hmac('sha256', $request->getContent(), config('services.sellauth.secret'));
    abort_unless(hash_equals($expected, (string) $request->header('X-Signature')), 401);

    // 2. Map the variant to a plan.
    $plan = LicensePlan::findOrFail(config('services.sellauth.plans')[$request->input('item.variant_id')]);

    // 3. Issue. The invoice item id makes retries return the same keys.
    $batch = app(Keys::class)->issue($plan, (int) $request->input('item.quantity', 1), new IssueContext(
        source: 'app', reference: 'sellauth:'.$request->input('item.id'), label: 'SellAuth order',
    ));

    // 4. Reply with plain text, one key per line.
    return response($batch->keys->pluck('key')->implode("\n"), 200)->header('Content-Type', 'text/plain');
});
```

Use the invoice item id, not the invoice id: one invoice can hold several items, and each gets its own request. SellAuth retries on timeouts, `429` and `5xx`, up to 3 attempts, with a 10 second timeout. Any other status marks the item failed and shows your response body to the buyer.

SellApp works the same way with three differences. ([SellApp docs](https://sell.app/docs/setting-up-dynamic-webhook))

* The signature header is `Signature`: HMAC-SHA256 of the raw body with the secret from your store's developer settings.
* Reply with plain text, or JSON such as `{"message": "Your key: ..."}`.
* SellApp does not document the body's field names, only that it holds the order data. The webhook URL is set per variant, so pass the plan in the URL (`/webhooks/sellapp/{plan}`). Read the order id field from a real order in the developer settings' delivery logs; test deliveries send mock data.

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
