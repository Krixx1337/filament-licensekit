<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Support\ModuleReadiness;
use LicenseKit\Tests\Core\Fixtures\User;

test('installer preserves existing host configuration on repeated execution', function () {
    $path = config_path('licensekit.php');
    $original = is_file($path) ? file_get_contents($path) : null;
    try {
        file_put_contents($path, "<?php\nreturn ['host_marker' => 'preserved'];\n");
        $this->artisan('licensekit:install', ['--no-interaction' => true])->assertSuccessful();
        $this->artisan('licensekit:install', ['--no-interaction' => true])->assertSuccessful();
        expect(file_get_contents($path))->toContain("'host_marker' => 'preserved'");
    } finally {
        if ($original === null) {
            unlink($path);
        } else {
            file_put_contents($path, $original);
        }
    }
});

test('installer publishes missing configuration without querying or migrating the database', function () {
    $path = config_path('licensekit.php');
    $original = is_file($path) ? file_get_contents($path) : null;
    if ($original !== null) {
        unlink($path);
    }
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        $this->artisan('licensekit:install', ['--no-interaction' => true])->assertSuccessful();
        expect(file_get_contents($path))->toBe(file_get_contents(__DIR__.'/../../../config/licensekit.php'));
        expect($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
        if ($original === null) {
            unlink($path);
        } else {
            file_put_contents($path, $original);
        }
    }
});

test('optional outage module alone governs its schema and registration', function (bool $enabled) {
    $this->bootModules($enabled, false);
    expect(Schema::hasTable('licensekit_outages'))->toBe($enabled);
    expect(Schema::hasTable('licensekit_compensation_operations'))->toBe($enabled);
    expect(Schema::hasTable('licensekit_product_releases'))->toBeFalse();
    expect(Schema::hasTable('licensekit_free_access_windows'))->toBeFalse();
    expect(Route::has('licensekit.download.show'))->toBeFalse();
    expect(Route::has('licensekit.licenses.issue'))->toBeFalse();
})->with([false, true]);

test('disabled outage module refuses admin operations without schema access', function () {
    $user = User::factory()->create();
    expect(fn () => app(ManageOutages::class)->start(Product::factory()->create(), 'incident', 'Maintenance', $user))->toThrow(LicensingException::class, 'module disabled');
    expect(Schema::hasTable('licensekit_outages'))->toBeFalse();
});

test('deep module diagnostics do not block unrelated batch creation', function () {
    config(['licensekit.modules.outages' => true]);
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing outages table: licensekit_outages.')->assertFailed();
    app(ModuleReadiness::class)->assertReady();
    $plan = LicensePlan::factory()->create();
    expect(app(CreateBatch::class)->create($plan, 1, 'fixture', 'test')->keys)->toHaveCount(1);
});
