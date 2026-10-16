<?php

use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Support\ModuleReadiness;
use LicenseKit\Support\SetupDiagnostics;
use LicenseKit\Tests\Filament\Fixtures\User;

test('invalid setup still boots Artisan and diagnostics while APIs fail closed without database access', function (array $settings, string $message) {
    $this->bootWithSettings($settings);
    $this->artisan('list')->assertSuccessful();
    $this->artisan('config:clear')->assertSuccessful();
    $this->artisan('licensekit:check')->expectsOutputToContain($message)->assertFailed();
    $this->artisan('licensekit:install')->expectsOutputToContain($message)->assertFailed();
    $panel = Filament::getPanel('admin');
    expect($panel->getResources())->toBe([]);
    expect(fn () => $panel->getPlugin('licensekit')->boot($panel))->toThrow(LicensingException::class, 'licensing unavailable');
    expect(fn () => app(ModuleReadiness::class)->assertReady())->toThrow(LicensingException::class, 'licensing unavailable');
    $connection = DB::connection();
    $connection->enableQueryLog();
    $issuance = $this->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => 1, 'quantity' => 1, 'reference' => 'unsafe']);
    if (config('licensekit.management.enabled') === true && app(SetupDiagnostics::class)->managementErrors() === []) {
        $issuance->assertStatus(503)->assertExactJson(['error' => 'licensing_unavailable', 'message' => 'licensing unavailable']);
    } else {
        $issuance->assertNotFound();
    }
    $this->postJson('/api/licensekit/v1/products/missing/login', ['login' => 'user@example.test', 'password' => 'password'])->assertNotFound();
    $this->getJson('/api/licensekit/v1/entitlements')->assertNotFound();
    expect($connection->getQueryLog())->toBe([]);
})->with([
    'missing guard' => [['licensekit.user.guard' => 'missing'], 'LicenseKit guard must have a Laravel user provider.'],
    'missing model' => [['auth.providers.users.model' => 'MissingUserModel'], 'LicenseKit user model must be an Eloquent Authenticatable model.'],
    'wrong model' => [['auth.providers.users.model' => stdClass::class], 'LicenseKit user model must be an Eloquent Authenticatable model.'],
    'unsupported provider' => [['auth.providers.users.driver' => 'missing-driver'], 'missing-driver'],
    'device storage' => [['licensekit.device_binding.storage' => 'hash'], 'licensekit.device_binding.storage must be plain or encrypted.'],
    'device scope' => [['licensekit.device_binding.scope' => 'provider'], 'licensekit.device_binding.scope must be per_product or global.'],
    'customer reset switch' => [['licensekit.device_binding.customer_reset.enabled' => 'false'], 'licensekit.device_binding.customer_reset.enabled must be true or false.'],
    'customer cooldown' => [['licensekit.device_binding.customer_reset.cooldown_days' => -1], 'licensekit.device_binding.customer_reset.cooldown_days must be a nonnegative integer.'],
    'customer reason flag' => [['licensekit.device_binding.customer_reset.require_reason' => 'false'], 'licensekit.device_binding.customer_reset.require_reason must be true or false.'],
    'management flag' => [['licensekit.management.enabled' => 'true'], 'Invalid licensekit.management.enabled.'],
    'empty display column' => [['licensekit.user.display_column' => ' '], 'licensekit.user.display_column must be a nonempty column name.'],
    'invalid display column' => [['licensekit.user.display_column' => []], 'licensekit.user.display_column must be a nonempty column name.'],
    'display column' => [['licensekit.user.display_column' => null], 'licensekit.user.display_column must be a nonempty column name.'],
    'management block' => [['licensekit.management' => false], 'licensekit.management must be an array.'],
    'management prefix' => [['licensekit.management.prefix' => []], 'Invalid licensekit.management.prefix.'],
    'management domain' => [['licensekit.management.domain' => 'bad/domain'], 'Invalid licensekit.management.domain.'],
    'management middleware' => [['licensekit.management.middleware' => 'web'], 'Invalid licensekit.management.middleware.'],
    'management limits' => [['licensekit.management.rate_limits.reads' => 0], 'Invalid licensekit.management.rate_limits.'],
    'verification flag' => [['licensekit.user.require_verified' => 'false'], 'licensekit.user.require_verified must be true or false.'],
    'column shape' => [['licensekit.user.suspended_column' => []], 'licensekit.user.suspended_column must be a nonempty column name or null.'],
]);

test('diagnostics identify panel guard mismatches without blocking Artisan bootstrap', function () {
    $this->bootWithSettings(['auth.guards.staff.provider' => 'missing']);
    $this->artisan('licensekit:check')->expectsOutput('Panel [admin] must use the configured LicenseKit host user model. Check its auth guard.')->assertFailed();
    expect(Filament::getPanel('admin')->getResources())->toBe([]);
});

test('diagnostics explain missing tables on a fresh installation', function () {
    Gate::define('licensekit.admin', fn () => true);
    $this->artisan('licensekit:check')->expectsOutput('Missing table: users. Prepare host/Sanctum migrations and run php artisan migrate.')->assertFailed();
});

test('database connection failure is sanitized without exposing its path', function () {
    $this->bootWithSettings(['database.connections.testing.database' => '/missing/private-secret/database.sqlite']);
    Gate::define('licensekit.admin', fn () => true);
    $this->artisan('licensekit:check')->expectsOutput('Database unavailable. Check the host database connection settings and connectivity.')
        ->doesntExpectOutputToContain('private-secret')->assertFailed();
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

test('diagnostics explain unsupported panel tenancy and refuse runtime boot', function () {
    $panel = Filament::getPanel('admin')->tenant(User::class);
    $this->artisan('licensekit:check')->expectsOutput('Panel [admin] uses tenancy. Register LicenseKit on a non-tenant panel.')->assertFailed();
    expect(fn () => $panel->getPlugin('licensekit')->boot($panel))->toThrow(LicensingException::class, 'licensing unavailable');
});

test('invalid management limits are diagnosed and prevent route registration', function () {
    $this->bootWithSettings(['licensekit.management.rate_limits.writes' => '60', 'licensekit.management.enabled' => true]);
    $this->artisan('licensekit:check')->expectsOutputToContain('Invalid licensekit.management.rate_limits.')->assertFailed();
    $this->postJson('/api/licensekit/v1/manage/batches', [])->assertNotFound();
    $this->getJson('/api/licensekit/v1/entitlements')->assertNotFound();
});
