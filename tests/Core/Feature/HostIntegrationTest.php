<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\CatalogWrites;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\IssueLicenses;
use LicenseKit\Core\Events\LicenseClaimed;
use LicenseKit\Core\Events\LicenseGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

function enableLocalStore(LicensePlan $plan): void
{
    config(['licensekit.local_integrations' => ['store' => ['enabled' => true, 'allowed_product_ids' => [$plan->product_id],
        'maximum_quantity' => 5, 'disclose_serials' => true]]]);
}

test('explicit catalog actor works without a Filament panel and still enforces policies', function () {
    $actor = testAdminActor();

    $product = CatalogWrites::create(Product::class, ['name' => 'Desktop', 'slug' => 'desktop'], $actor);

    $this->assertModelExists($product);
    expect(fn () => CatalogWrites::update($product, ['name' => 'Denied']))->toThrow(LicensingException::class, 'admin forbidden');
});

test('local issuance retries return original keys and duration after a plan is disabled', function () {
    $plan = LicensePlan::factory()->create(['duration_days' => 30]);
    enableLocalStore($plan);
    $issuer = app(IssueLicenses::class);
    $first = $issuer->issue('store', $plan->id, 2, 'purchase:1', discloseSerials: true);
    $plan->update(['is_enabled' => false, 'duration_days' => 60]);

    $retry = $issuer->issue('store', $plan->id, 2, 'purchase:1', discloseSerials: true);

    expect($first->replayed)->toBeFalse();
    expect($retry->replayed)->toBeTrue();
    expect($retry->keyIds)->toBe($first->keyIds);
    expect($retry->serials)->toBe($first->serials);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
    expect(LicenseKey::firstOrFail()->duration_days)->toBe(30);
});

test('local integration restrictions reject issuance without partial writes', function (string $failure) {
    $plan = LicensePlan::factory()->create();
    enableLocalStore($plan);
    match ($failure) {
        'disabled' => config(['licensekit.local_integrations.store.enabled' => false]),
        'scope' => config(['licensekit.local_integrations.store.allowed_product_ids' => []]),
        'quantity' => config(['licensekit.local_integrations.store.maximum_quantity' => 0]),
        'disclosure' => config(['licensekit.local_integrations.store.disclose_serials' => false]),
    };

    expect(fn () => app(IssueLicenses::class)->issue('store', $plan->id, 1, 'purchase', discloseSerials: true))
        ->toThrow(LicensingException::class, 'integration forbidden');

    $this->assertDatabaseCount('licensekit_batches', 0);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
})->with(['disabled', 'scope', 'quantity', 'disclosure']);

test('local issuance hides serials by default and rejects conflicting retries', function () {
    $plan = LicensePlan::factory()->create();
    enableLocalStore($plan);
    $first = app(IssueLicenses::class)->issue('store', $plan->id, 1, 'purchase');

    expect($first->serials)->toBeNull();
    expect(fn () => app(IssueLicenses::class)->issue('store', $plan->id, 2, 'purchase'))
        ->toThrow(LicensingException::class, 'batch conflict');
    config(['licensekit.local_integrations.store.enabled' => false]);
    expect(fn () => app(IssueLicenses::class)->issue('store', $plan->id, 1, 'purchase'))
        ->toThrow(LicensingException::class, 'integration forbidden');
    $this->assertDatabaseCount('licensekit_license_keys', 1);
});

test('local issuance joins host transaction rollback', function () {
    $plan = LicensePlan::factory()->create();
    enableLocalStore($plan);

    DB::beginTransaction();
    app(IssueLicenses::class)->issue('store', $plan->id, 1, 'purchase');
    DB::rollBack();

    $this->assertDatabaseCount('licensekit_batches', 0);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('claimed event contains linked provenance and waits for the host transaction', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $received = [];
    Event::listen(LicenseClaimed::class, function (LicenseClaimed $event) use (&$received): void {
        $received[] = $event->claim;
    });

    DB::beginTransaction();
    $license = app(ClaimLicense::class)->claim($user, $key->key);
    expect($received)->toBe([]);
    DB::commit();

    expect($received)->toHaveCount(1);
    expect($received[0]->keyId)->toBe($key->id);
    expect($received[0]->licenseId)->toBe($license->id);
    expect($received[0]->reference)->toBe($key->batch->reference);
    expect($key->fresh()->license_id)->toBe($license->id);
});

test('host rollback discards claim notification and restores key state', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $received = [];
    Event::listen(LicenseClaimed::class, function ($event) use (&$received): void {
        $received[] = $event;
    });

    DB::beginTransaction();
    app(ClaimLicense::class)->claim($user, $key->key);
    DB::rollBack();

    expect($received)->toBe([]);
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_licenses', 0);
});

test('completed claim provenance captures changes saved by synchronous grant listeners', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $received = [];
    Event::listen(LicenseGranted::class, function (LicenseGranted $event): void {
        License::whereKey($event->license->id)->update(['expires_at' => '2035-01-01 00:00:00']);
    });
    Event::listen(LicenseClaimed::class, function (LicenseClaimed $event) use (&$received): void {
        $received[] = $event->claim;
    });

    app(ClaimLicense::class)->claim($user, $key->key);

    expect($received)->toHaveCount(1);
    expect($received[0]->after['expires_at'])->toBe('2035-01-01T00:00:00+00:00');
});

test('initial schema can roll back and reinstall for fresh licensing', function () {
    LicenseKey::factory()->create();
    $migration = require __DIR__.'/../../../database/migrations/core/2026_10_12_000001_create_licensekit_core_tables.php';

    $migration->down();

    expect(Schema::hasTable('licensekit_license_keys'))->toBeFalse();
    $migration->up();
    $key = LicenseKey::factory()->create();
    $user = User::factory()->create();
    app(ClaimLicense::class)->claim($user, $key->key);
    $this->assertDatabaseCount('licensekit_licenses', 1);
});
