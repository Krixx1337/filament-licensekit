<?php

use LicenseKit\Core\Actions\ImportLicensing;
use LicenseKit\Core\Contracts\KeyNormalizer;
use LicenseKit\Core\Data\ImportSource;
use LicenseKit\Core\Data\KeySnapshot;
use LicenseKit\Core\Models\LicensePlan;

test('concurrent local issuance returns complete keys and one replay', function (bool $readCommitted) {
    $plan = LicensePlan::factory()->create();

    $results = $this->race(['local-issue', 'local-issue'], ['plan_id' => $plan->id, 'read_committed' => $readCommitted]);

    expect($results[0]['batch_id'])->toBeInt()->toBe($results[1]['batch_id']);
    expect($results[0]['key_ids'])->toHaveCount(2)->toBe($results[1]['key_ids']);
    expect($results[0]['serials'])->toHaveCount(2)->toBe($results[1]['serials']);
    expect($results[0]['replayed'])->not->toBe($results[1]['replayed']);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
})->with([false, true]);

test('local issuance returns complete committed keys inside an older host snapshot', function (bool $readCommitted) {
    $plan = LicensePlan::factory()->create();

    $results = $this->race(['local-issue', 'local-issue-stale'], [
        'plan_id' => $plan->id, 'product_id' => $plan->product_id,
        'read_committed' => $readCommitted, 'stale_snapshot' => true,
    ], 'local-issue');

    expect($results[0]['replayed'])->toBeFalse();
    expect($results[1]['replayed'])->toBeTrue();
    expect($results[1]['batch_id'])->toBe($results[0]['batch_id']);
    expect($results[1]['key_ids'])->toHaveCount(2)->toBe($results[0]['key_ids']);
    expect($results[1]['serials'])->toHaveCount(2)->toBe($results[0]['serials']);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
})->with([false, true]);

test('concurrent historical import retries create one mapping and key', function (bool $readCommitted) {
    $plan = LicensePlan::factory()->create();

    $results = $this->race(['import-key', 'import-key'], ['plan_id' => $plan->id, 'read_committed' => $readCommitted]);

    expect($results[0])->toBeInt()->toBe($results[1]);
    $this->assertDatabaseCount('licensekit_import_records', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 1);
})->with([false, true]);

test('MySQL preserves case-sensitive imported key equality', function () {
    config(['licensekit.imports.enabled' => true]);
    app()->instance(KeyNormalizer::class, new class implements KeyNormalizer
    {
        public function normalize(string $value): string
        {
            return trim($value);
        }
    });
    $plan = LicensePlan::factory()->create();
    $importer = app(ImportLicensing::class);

    $importer->key(new ImportSource('old', 'upper', 'migration'), new KeySnapshot('Old/Abc', $plan->id, 30));
    $importer->key(new ImportSource('old', 'lower', 'migration'), new KeySnapshot('Old/abc', $plan->id, 30));

    $this->assertDatabaseCount('licensekit_license_keys', 2);
});
