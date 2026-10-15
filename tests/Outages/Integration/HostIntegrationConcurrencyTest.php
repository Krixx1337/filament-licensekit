<?php

use LicenseKit\Core\Actions\ImportLicensing;
use LicenseKit\Core\Contracts\KeyNormalizer;
use LicenseKit\Core\Data\ImportSource;
use LicenseKit\Core\Data\KeySnapshot;
use LicenseKit\Core\Models\LicensePlan;

test('concurrent local issuance returns one stable batch', function () {
    $plan = LicensePlan::factory()->create();

    $results = $this->race(['local-issue', 'local-issue'], ['plan_id' => $plan->id]);

    expect($results[0])->toBeInt()->toBe($results[1]);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
});

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
