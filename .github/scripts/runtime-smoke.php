<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Core\Models\Product;
use LicenseKit\LicenseKitServiceProvider;

require getcwd().'/vendor/autoload.php';

class CompatibilityUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

try {
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }

    $app = Application::configure(basePath: getcwd())
        ->withExceptions()
        ->withProviders([LicenseKitServiceProvider::class])
        ->create();

    $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'auth.defaults.guard' => 'web',
            'auth.guards.web' => ['driver' => 'session', 'provider' => 'users'],
            'auth.providers.users' => ['driver' => 'eloquent', 'model' => CompatibilityUser::class],
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'cache.default' => 'array',
            'session.driver' => 'array',
            'licensekit.modules.outages' => true,
        ]);
    });

    $app->make(Kernel::class)->bootstrap();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->string('password');
        $table->timestamps();
    });

    // Run registered migrations directly so this test isolates package compatibility
    // from console changes in newer transitive Symfony versions.
    $migrator = $app->make('migrator');
    if (! $migrator->repositoryExists()) {
        $app->make('migration.repository')->createRepository();
    }
    $migrator->run($migrator->paths());

    foreach (['licensekit_products', 'licensekit_device_bindings', 'licensekit_outages', 'licensekit_compensation_entries'] as $table) {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException('Missing migrated table: '.$table);
        }
    }

    $user = CompatibilityUser::create(['email' => 'compatibility@example.test', 'password' => 'unused']);
    $product = Product::create(['name' => 'Compatibility', 'slug' => 'compatibility']);
    if ($product->fresh()->name !== 'Compatibility' || AuditEvent::where('subject_id', $product->id)->count() !== 1) {
        throw new RuntimeException('Product creation or observer audit failed.');
    }

    $binding = DeviceBinding::create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id, 'hwid' => ['value' => 'private-hwid']])->fresh();
    if ($binding->hwid !== ['value' => 'private-hwid'] || array_key_exists('hwid', $binding->toArray())) {
        throw new RuntimeException('Device binding storage or serialization privacy failed.');
    }

    $active = LicensingBan::create(['user_id' => $user->id, 'product_id' => $product->id, 'reason' => 'Active', 'created_by' => 'console']);
    LicensingBan::create(['user_id' => $user->id, 'product_id' => $product->id, 'reason' => 'Expired', 'created_by' => 'console', 'expires_at' => now()->subDay()]);
    LicensingBan::create(['user_id' => $user->id, 'product_id' => $product->id, 'reason' => 'Lifted', 'created_by' => 'console', 'lifted_at' => now()]);
    if (LicensingBan::query()->active()->pluck('id')->all() !== [$active->id]) {
        throw new RuntimeException('Active ban scope included expired or lifted bans.');
    }

    echo 'Runtime smoke passed on Laravel '.$app->version().' / PHP '.PHP_VERSION.".\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception."\n");
    exit(1);
}
