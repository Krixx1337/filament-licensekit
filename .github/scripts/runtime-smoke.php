<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
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
        ->withProviders([\Livewire\LivewireServiceProvider::class, LicenseKitServiceProvider::class])
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

    foreach (['licensekit_products', 'licensekit_licenses'] as $table) {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException('Missing migrated table: '.$table);
        }
    }

    $user = CompatibilityUser::create(['email' => 'customer@example.test', 'password' => password_hash('password', PASSWORD_BCRYPT)]);
    $product = Product::create(['name' => 'Compatibility', 'slug' => 'compatibility']);
    $plan = \LicenseKit\Core\Models\LicensePlan::create(['product_id' => $product->id, 'name' => '30 days', 'duration_days' => 30]);
    $issuer = app(\LicenseKit\Core\Actions\Keys::class);
    $context = new \LicenseKit\Core\Data\IssueContext(reference: 'purchase:1');
    $first = $issuer->issue($plan, 1, $context);
    $retry = $issuer->issue($plan, 1, $context);
    if ($first->id !== $retry->id || $first->keys[0]->key !== $retry->keys[0]->key) {
        throw new RuntimeException('Native issuance retry failed.');
    }
    $sessions = app(\LicenseKit\Core\Actions\LoaderSessions::class);
    $restricted = $sessions->login($product, $user->email, 'password', 'pc');
    if ($restricted['access'] !== false) { throw new RuntimeException('Unlicensed login granted access.'); }
    $redeemed = $sessions->redeem($product, $restricted['token'], $first->keys[0]->key);
    if ($redeemed['access'] !== true || $sessions->heartbeat($product, $restricted['token'], 'pc')['access'] !== true) {
        throw new RuntimeException('Loader redemption or heartbeat failed.');
    }
    $signing = app(\LicenseKit\Core\Actions\ResponseSigning::class);
    $signing->install();
    $body = json_encode($redeemed, JSON_THROW_ON_ERROR);
    if (! sodium_crypto_sign_verify_detached(base64_decode($signing->sign($body)), $body, base64_decode($signing->publicKey()))) {
        throw new RuntimeException('Response signing failed.');
    }
    $license = License::sole();
    if ($license->device !== 'pc' || array_key_exists('device', $license->toArray()) || ActivityEntry::where('event', 'key_redeemed')->count() !== 1) {
        throw new RuntimeException('License state, privacy or activity failed.');
    }
    app(\LicenseKit\Core\Actions\ManageLicenses::class)->suspend($license, $user);
    if (\LicenseKit\Core\Models\LoaderSession::count() !== 0) { throw new RuntimeException('Session invalidation failed.'); }

    echo 'Runtime smoke passed on Laravel '.$app->version().' / PHP '.PHP_VERSION.".\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception."\n");
    exit(1);
}
