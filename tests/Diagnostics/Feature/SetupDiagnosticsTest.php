<?php

use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use LicenseKit\Filament\Pages\SetupNeeded;
use LicenseKit\Support\SetupDiagnostics;
use LicenseKit\Tests\Filament\Fixtures\User;

test('invalid setup keeps panel resources and setup page available', function (array $settings) {
    $this->bootWithSettings($settings);
    $this->artisan('list')->assertSuccessful();
    $this->artisan('licensekit:check')->assertFailed();
    expect(Filament::getPanel('admin')->getResources())->not->toBeEmpty();
    expect(Filament::getPanel('admin')->getPages())->toContain(SetupNeeded::class);
})->with([
    [['licensekit.user_guard' => 'missing']], [['licensekit.device_matcher' => stdClass::class]],
    [['licensekit.store_api_enabled' => true, 'licensekit.store_api_prefix' => []]],
    [['licensekit.user_label' => []]], [['licensekit.max_batch_size' => 0]],
]);

test('diagnostics explain fresh schema and sanitize unavailable database', function () {
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing table: licensekit_products')->assertFailed();
    $this->bootWithSettings(['database.connections.testing.database' => '/missing/private-secret/database.sqlite']);
    $this->artisan('licensekit:check')->expectsOutputToContain('Database unavailable.')->doesntExpectOutputToContain('private-secret')->assertFailed();
});

test('diagnostics report panel tenancy without failing panel boot', function () {
    $panel = Filament::getPanel('admin')->tenant(User::class);
    $panel->getPlugin('licensekit')->boot($panel);
    expect(app(SetupDiagnostics::class)->issues())->toContain('Register LicenseKit on a non-tenant panel.');
});

test('installer returns failure when configuration publishing fails', function (string $mode) {
    $path = config_path('licensekit.php');
    $original = is_file($path) ? file_get_contents($path) : null;
    if ($original !== null) {
        unlink($path);
    }
    $publisher = new class($mode) extends Command
    {
        protected $signature = 'vendor:publish {--tag=*}';

        public function __construct(private string $mode)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            if ($this->mode === 'throw') {
                throw new ErrorException('private-secret filesystem failure');
            }

            return $this->mode === 'missing' ? self::SUCCESS : self::FAILURE;
        }
    };
    $this->app->make(Kernel::class)->registerCommand($publisher);
    try {
        $this->artisan('licensekit:install')->expectsOutput('LicenseKit configuration could not be published. Check config directory permissions and retry.')
            ->doesntExpectOutputToContain('private-secret')->assertFailed();
        expect(is_file($path))->toBeFalse();
    } finally {
        if ($original !== null) {
            file_put_contents($path, $original);
        }
    }
})->with(['exit', 'throw', 'missing']);
