<?php

use Carbon\CarbonImmutable;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00Z')));

test('adding time extends running licenses and banks paused ones', function () {
    $actor = testAdminActor();
    $product = Product::factory()->create();
    $owned = fn (array $state = []) => License::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, ...$state]);
    $running = $owned(['expires_at' => '2026-10-20 12:00:00']);
    $paused = $owned(['expires_at' => null, 'paused_at' => '2026-10-10 00:00:00', 'remaining_seconds' => 3600]);
    $expired = $owned(['expires_at' => '2026-10-15 12:00:00']);
    $lifetime = $owned(['is_lifetime' => true, 'expires_at' => null]);
    $revoked = $owned(['status' => 'revoked']);
    $orphan = License::factory()->create(['product_id' => $product->id, 'user_id' => null]);
    $otherProduct = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => '2026-10-20 12:00:00']);
    $untouched = collect([$expired, $lifetime, $revoked, $orphan, $otherProduct])->mapWithKeys(fn (License $e) => [$e->id => $e->fresh()->getAttributes()]);

    expect(app(ManageLicenses::class)->previewAddTimeToProduct($product))->toBe(2);
    $count = app(ManageLicenses::class)->addTimeToProduct($product, 7200, $actor, 'Server outage');

    expect($count)->toBe(2)
        ->and($running->fresh()->expires_at->toIso8601String())->toBe('2026-10-20T14:00:00+00:00')
        ->and($paused->fresh()->remaining_seconds)->toBe(10800)
        ->and($paused->fresh()->expires_at)->toBeNull();
    foreach ($untouched as $id => $attributes) {
        expect(License::findOrFail($id)->getAttributes())->toBe($attributes);
    }
    $audit = ActivityEntry::where('event', 'product_time_added')->sole();
    expect($audit->meta['after']['license_count'])->toBe(2)->and($audit->meta['note'])->toBe('Server outage');
});

test('a since date also credits licenses that expired after it, from their old end date', function () {
    $actor = testAdminActor();
    $product = Product::factory()->create();
    $recent = License::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'expires_at' => '2026-10-16 06:00:00']);
    $old = License::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'expires_at' => '2026-10-01 00:00:00']);
    $since = CarbonImmutable::parse('2026-10-16T00:00:00Z');

    expect(app(ManageLicenses::class)->previewAddTimeToProduct($product, $since))->toBe(1);
    app(ManageLicenses::class)->addTimeToProduct($product, 86400, $actor, since: $since);

    expect($recent->fresh()->expires_at->toIso8601String())->toBe('2026-10-17T06:00:00+00:00')
        ->and($old->fresh()->expires_at->toIso8601String())->toBe('2026-10-01T00:00:00+00:00');
});

test('adding time rejects invalid input without changes', function (int $seconds, ?string $since, string $error) {
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $before = $license->fresh()->getAttributes();

    expect(fn () => app(ManageLicenses::class)->addTimeToProduct($license->product, $seconds, $actor,
        since: $since === null ? null : CarbonImmutable::parse($since)))->toThrow(LicensingException::class, $error);

    expect($license->fresh()->getAttributes())->toBe($before);
    expect(ActivityEntry::where('event', 'product_time_added')->count())->toBe(0);
})->with([
    'zero' => [0, null, 'invalid duration'],
    'too long' => [ManageLicenses::MAXIMUM_ADDED_SECONDS + 1, null, 'invalid duration'],
    'future since' => [3600, '2026-10-17T00:00:00Z', 'invalid eligibility date'],
]);
