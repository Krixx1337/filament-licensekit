<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\CanonicalKeys;
use LicenseKit\Core\Actions\CatalogWrites;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\ImportLicensing;
use LicenseKit\Core\Actions\IssueLicenses;
use LicenseKit\Core\Actions\StandardKeyNormalizer;
use LicenseKit\Core\Contracts\KeyNormalizer;
use LicenseKit\Core\Data\DeviceSnapshot;
use LicenseKit\Core\Data\EntitlementSnapshot;
use LicenseKit\Core\Data\ImportSource;
use LicenseKit\Core\Data\KeySnapshot;
use LicenseKit\Core\Data\ResetSnapshot;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Core\Events\LicenseClaimed;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

class SyntheticKeyNormalizer implements KeyNormalizer
{
    public function normalize(#[SensitiveParameter] string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, 'OLD/')) {
            return $value;
        }

        return (new StandardKeyNormalizer)->normalize($value);
    }
}

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

test('custom and standard keys use the same claim path', function () {
    config(['licensekit.key_normalizer' => SyntheticKeyNormalizer::class, 'licensekit.imports.enabled' => true]);
    $plan = LicensePlan::factory()->create();
    $user = User::factory()->create();
    $import = app(ImportLicensing::class)->key(new ImportSource('old-store', 'key:1', 'migration'), new KeySnapshot('OLD/Abc-123', $plan->id, 30));
    enableLocalStore($plan);
    $new = app(IssueLicenses::class)->issue('store', $plan->id, 1, 'purchase', discloseSerials: true);
    $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));

    $entitlement = app(ClaimLicense::class)->claim($user, ' OLD/Abc-123 ');
    app(ClaimLicense::class)->claim($user, $new->serials[0]);

    expect($entitlement->fresh()->expires_at->equalTo(now()->addDays(60)))->toBeTrue();
    $this->assertDatabaseHas('licensekit_license_keys', ['id' => $import->targetId, 'entitlement_id' => $entitlement->id]);
    expect(fn () => app(ClaimLicense::class)->claim($user, 'OLD/Abc-123'))->toThrow(LicensingException::class, 'key already claimed');
});

test('canonical key bounds and idempotence fail safely', function (string $mode) {
    app()->instance(KeyNormalizer::class, new class($mode) implements KeyNormalizer
    {
        public function __construct(private string $mode) {}

        public function normalize(string $value): string
        {
            return match ($this->mode) {
                'long' => str_repeat('A', 256), 'unicode' => 'Ã©', 'whitespace' => 'A ',
                'unstable' => $value.'A', 'empty' => '',
            };
        }
    });

    expect(fn () => app(CanonicalKeys::class)->normalize('abc'))->toThrow(LicensingException::class, 'invalid key normalizer');
})->with(['long', 'unicode', 'whitespace', 'unstable', 'empty']);

test('normalizer cannot make generated keys unclaimable', function () {
    $plan = LicensePlan::factory()->create();
    enableLocalStore($plan);
    app()->instance(KeyNormalizer::class, new class implements KeyNormalizer
    {
        public function normalize(string $value): string
        {
            return 'canonical';
        }
    });

    expect(fn () => app(IssueLicenses::class)->issue('store', $plan->id, 1, 'purchase'))
        ->toThrow(LicensingException::class, 'invalid key normalizer');
    $this->assertDatabaseCount('licensekit_batches', 0);
});

test('imports are disabled by default', function () {
    $product = Product::factory()->create();

    expect(fn () => app(ImportLicensing::class)->entitlement(new ImportSource('old', '1', 'migration'), new EntitlementSnapshot(null, $product->id)))
        ->toThrow(LicensingException::class, 'imports disabled');
    $this->assertDatabaseCount('licensekit_import_records', 0);
});

test('preflight validates without writing and reports live state conflicts', function () {
    config(['licensekit.imports.enabled' => true]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $snapshot = new EntitlementSnapshot($user->id, $product->id, expiresAt: CarbonImmutable::parse('2030-01-01T00:00:00Z'));
    $source = new ImportSource('old', '1', 'migration');
    $auditCount = AuditEvent::count();

    $result = app(ImportLicensing::class)->entitlement($source, $snapshot, dryRun: true);

    expect($result->targetId)->toBeNull();
    expect($result->dryRun)->toBeTrue();
    $this->assertDatabaseCount('licensekit_import_records', 0);
    $this->assertDatabaseCount('licensekit_entitlements', 0);
    $this->assertDatabaseCount('licensekit_audit_events', $auditCount);
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    expect(fn () => app(ImportLicensing::class)->entitlement($source, $snapshot, dryRun: true))
        ->toThrow(LicensingException::class, 'import target conflict');
});

test('historical entitlement imports preserve instants and retries never overwrite live changes', function () {
    config(['licensekit.imports.enabled' => true]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $source = new ImportSource('old', '1', 'migration');
    $snapshot = new EntitlementSnapshot($user->id, $product->id, expiresAt: CarbonImmutable::parse('2030-01-01T03:00:00+03:00'));
    $importer = app(ImportLicensing::class);
    $first = $importer->entitlement($source, $snapshot);
    $entitlement = Entitlement::findOrFail($first->targetId);
    expect($entitlement->expires_at->toIso8601String())->toBe('2030-01-01T00:00:00+00:00');
    $entitlement->update(['expires_at' => '2031-01-01 00:00:00']);

    $retry = $importer->entitlement($source, $snapshot);

    expect($retry->targetId)->toBe($first->targetId);
    expect($retry->replayed)->toBeTrue();
    expect($entitlement->fresh()->expires_at->year)->toBe(2031);
    expect(fn () => $importer->entitlement($source, new EntitlementSnapshot($user->id, $product->id, isLifetime: true)))
        ->toThrow(LicensingException::class, 'import source conflict');
    $this->assertDatabaseCount('licensekit_entitlements', 1);
});

test('historical key and device imports do not replay claims and preserve reset cooldown', function () {
    config(['licensekit.imports.enabled' => true, 'licensekit.device_binding.scope' => 'global']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
    $plan = LicensePlan::factory()->create();
    $user = User::factory()->create();
    $importer = app(ImportLicensing::class);
    Event::fake([EntitlementGranted::class, LicenseClaimed::class]);
    $entitlement = $importer->entitlement(new ImportSource('old', 'entitlement:1', 'migration'),
        new EntitlementSnapshot($user->id, $plan->product_id, isLifetime: true));
    $key = new KeySnapshot('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', $plan->id, null, $entitlement->targetId, CarbonImmutable::now()->subYear());
    $device = new DeviceSnapshot($user->id, $plan->product_id, ['value' => 'old-pc'], CarbonImmutable::now()->subYear(), CarbonImmutable::now()->subDay());
    $reset = new ResetSnapshot($user->id, null, CarbonImmutable::now()->subDay(), 'admin', 'deleted-admin');

    foreach ([false, true] as $retry) {
        expect($importer->key(new ImportSource('old', 'key:1', 'migration'), $key)->replayed)->toBe($retry);
        expect($importer->device(new ImportSource('old', 'device:1', 'migration'), $device)->replayed)->toBe($retry);
        expect($importer->reset(new ImportSource('old', 'reset:1', 'migration'), $reset)->replayed)->toBe($retry);
    }

    Event::assertNotDispatched(EntitlementGranted::class);
    Event::assertNotDispatched(LicenseClaimed::class);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 1);
    $this->assertDatabaseCount('licensekit_device_bindings', 1);
    $this->assertDatabaseCount('licensekit_device_reset_history', 1);
    expect(DeviceBinding::firstOrFail()->last_hwid_reset_at->equalTo(now()->subDay()))->toBeTrue();
    expect(DeviceResetHistory::firstOrFail()->actor_id)->toBe('deleted-admin');
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'key already claimed');
});

test('canonical collisions reject imports while case-sensitive custom keys stay distinct', function () {
    config(['licensekit.imports.enabled' => true, 'licensekit.key_normalizer' => SyntheticKeyNormalizer::class]);
    $plan = LicensePlan::factory()->create();
    $importer = app(ImportLicensing::class);
    $importer->key(new ImportSource('old', '1', 'migration'), new KeySnapshot('OLD/Abc', $plan->id, 30));
    $importer->key(new ImportSource('old', '2', 'migration'), new KeySnapshot('OLD/abc', $plan->id, 30));

    expect(fn () => $importer->key(new ImportSource('old', '3', 'migration'), new KeySnapshot(' OLD/Abc ', $plan->id, 30)))
        ->toThrow(LicensingException::class, 'import target conflict');
    $this->assertDatabaseCount('licensekit_license_keys', 2);
    $this->assertDatabaseCount('licensekit_import_records', 2);
});

test('invalid imported states fail without partial history', function (string $kind) {
    config(['licensekit.imports.enabled' => true]);
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $importer = app(ImportLicensing::class);
    $source = new ImportSource('old', '1', 'migration');

    expect(fn () => match ($kind) {
        'lifetime_expiry' => $importer->entitlement($source, new EntitlementSnapshot($user->id, $plan->product_id, isLifetime: true, expiresAt: CarbonImmutable::now())),
        'paused_without_remaining' => $importer->entitlement($source, new EntitlementSnapshot($user->id, $plan->product_id, pausedAt: CarbonImmutable::now())),
        'claimed_without_entitlement' => $importer->key($source, new KeySnapshot('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', $plan->id, 30, claimedAt: CarbonImmutable::now())),
        'bound_without_hwid' => $importer->device($source, new DeviceSnapshot($user->id, $plan->product_id, null, CarbonImmutable::now())),
    })->toThrow(LicensingException::class, 'invalid import state');
    $this->assertDatabaseCount('licensekit_import_records', 0);
    $this->assertDatabaseCount('licensekit_entitlements', 0);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
})->with(['lifetime_expiry', 'paused_without_remaining', 'claimed_without_entitlement', 'bound_without_hwid']);

test('claimed event contains linked provenance and waits for the host transaction', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $received = [];
    Event::listen(LicenseClaimed::class, function (LicenseClaimed $event) use (&$received): void {
        $received[] = $event->claim;
    });

    DB::beginTransaction();
    $entitlement = app(ClaimLicense::class)->claim($user, $key->key);
    expect($received)->toBe([]);
    DB::commit();

    expect($received)->toHaveCount(1);
    expect($received[0]->keyId)->toBe($key->id);
    expect($received[0]->entitlementId)->toBe($entitlement->id);
    expect($received[0]->reference)->toBe($key->batch->reference);
    expect($key->fresh()->entitlement_id)->toBe($entitlement->id);
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
    $this->assertDatabaseCount('licensekit_entitlements', 0);
});

test('preflight reports safe source identifiers and domain errors without serials', function () {
    config(['licensekit.imports.enabled' => true]);
    $plan = LicensePlan::factory()->create();

    $report = app(ImportLicensing::class)->preflight(new ImportSource('old', 'row:9', 'migration'), new KeySnapshot('secret-invalid-serial', $plan->id, 30));

    expect($report)->toBe(['source_namespace' => 'old', 'source_reference' => 'row:9', 'record_type' => 'key', 'valid' => false, 'error' => 'invalid_key']);
    $this->assertDatabaseCount('licensekit_import_records', 0);
});

test('historical import dates stay UTC in a non-UTC host', function () {
    config(['licensekit.imports.enabled' => true]);
    date_default_timezone_set('America/New_York');
    config(['app.timezone' => 'America/New_York']);
    try {
        $user = User::factory()->create();
        $plan = LicensePlan::factory()->create();
        $at = CarbonImmutable::parse('2025-01-01T03:00:00+03:00');
        $importer = app(ImportLicensing::class);
        $entitlement = $importer->entitlement(new ImportSource('old', 'e', 'migration'), new EntitlementSnapshot($user->id, $plan->product_id, isLifetime: true));
        $key = $importer->key(new ImportSource('old', 'k', 'migration'), new KeySnapshot('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', $plan->id, null, $entitlement->targetId, $at, $at));
        $device = $importer->device(new ImportSource('old', 'd', 'migration'), new DeviceSnapshot($user->id, $plan->product_id, ['value' => 'pc'], $at, $at));

        expect(LicenseKey::findOrFail($key->targetId)->claimed_at->toIso8601String())->toBe('2025-01-01T00:00:00+00:00');
        expect(DeviceBinding::findOrFail($device->targetId)->last_hwid_reset_at->toIso8601String())->toBe('2025-01-01T00:00:00+00:00');
    } finally {
        date_default_timezone_set('UTC');
    }
});

test('paused and deleted-owner entitlement history imports without clock advancement', function () {
    config(['licensekit.imports.enabled' => true]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $importer = app(ImportLicensing::class);
    $paused = $importer->entitlement(new ImportSource('old', 'p', 'migration'), new EntitlementSnapshot($user->id, $product->id,
        pausedAt: CarbonImmutable::parse('2024-01-01T00:00:00Z'), remainingSeconds: 900));
    $deleted = $importer->entitlement(new ImportSource('old', 'deleted', 'migration'), new EntitlementSnapshot(null, $product->id, isRevoked: true));

    expect(Entitlement::findOrFail($paused->targetId)->remaining_seconds)->toBe(900);
    expect(Entitlement::findOrFail($paused->targetId)->expires_at)->toBeNull();
    expect(Entitlement::findOrFail($deleted->targetId)->user_id)->toBeNull();
});

test('255-byte custom keys redeem without truncation and oversized input fails', function () {
    config(['licensekit.imports.enabled' => true, 'licensekit.key_normalizer' => SyntheticKeyNormalizer::class]);
    $plan = LicensePlan::factory()->create();
    $user = User::factory()->create();
    $serial = 'OLD/'.str_repeat('x', 251);
    app(ImportLicensing::class)->key(new ImportSource('old', 'long', 'migration'), new KeySnapshot($serial, $plan->id, 30));

    app(ClaimLicense::class)->claim($user, $serial);

    expect(LicenseKey::firstOrFail()->key)->toBe($serial);
    expect(fn () => app(CanonicalKeys::class)->normalize($serial.'x'))->toThrow(LicensingException::class, 'invalid key');
});

test('import rollback leaves no source mapping, batch or key', function () {
    config(['licensekit.imports.enabled' => true]);
    $plan = LicensePlan::factory()->create();

    DB::beginTransaction();
    app(ImportLicensing::class)->key(new ImportSource('old', 'key', 'migration'), new KeySnapshot('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', $plan->id, 30));
    DB::rollBack();

    $this->assertDatabaseCount('licensekit_import_records', 0);
    $this->assertDatabaseCount('licensekit_batches', 0);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('completed claim provenance captures changes saved by synchronous grant listeners', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $received = [];
    Event::listen(EntitlementGranted::class, function (EntitlementGranted $event): void {
        Entitlement::whereKey($event->entitlement->id)->update(['expires_at' => '2035-01-01 00:00:00']);
    });
    Event::listen(LicenseClaimed::class, function (LicenseClaimed $event) use (&$received): void {
        $received[] = $event->claim;
    });

    app(ClaimLicense::class)->claim($user, $key->key);

    expect($received)->toHaveCount(1);
    expect($received[0]->after['expires_at'])->toBe('2035-01-01T00:00:00+00:00');
});

test('initial schema can roll back imported history and reinstall for fresh licensing', function () {
    config(['licensekit.imports.enabled' => true]);
    $plan = LicensePlan::factory()->create();
    app(ImportLicensing::class)->key(new ImportSource('old', 'key:1', 'migration'), new KeySnapshot('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', $plan->id, 30));
    $migration = require __DIR__.'/../../../database/migrations/core/2026_10_12_000001_create_licensekit_core_tables.php';

    $migration->down();

    expect(Schema::hasTable('licensekit_import_records'))->toBeFalse();
    expect(Schema::hasTable('licensekit_license_keys'))->toBeFalse();
    $migration->up();
    $plan = LicensePlan::factory()->create();
    $user = User::factory()->create();
    app(ImportLicensing::class)->key(new ImportSource('old', 'key:1', 'migration'), new KeySnapshot('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', $plan->id, 30));
    app(ClaimLicense::class)->claim($user, 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE');
    $this->assertDatabaseCount('licensekit_import_records', 1);
    $this->assertDatabaseCount('licensekit_entitlements', 1);
});
