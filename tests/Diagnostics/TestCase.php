<?php

namespace LicenseKit\Tests\Diagnostics;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use LicenseKit\LicenseKitServiceProvider;
use LicenseKit\Tests\Filament\Fixtures\TestPanelProvider;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    private ?string $originalConfiguration = null;

    /** @var array<string, mixed> */
    public array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $path = config_path('licensekit.php');
        $this->originalConfiguration = is_file($path) ? file_get_contents($path) : null;
    }

    protected function tearDown(): void
    {
        try {
            $path = config_path('licensekit.php');
            if ($this->originalConfiguration !== null) {
                file_put_contents($path, $this->originalConfiguration);
            } elseif (is_file($path)) {
                unlink($path);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function getPackageProviders($app): array
    {
        $providers = [SanctumServiceProvider::class, LivewireServiceProvider::class,
            BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class];
        $installed = json_decode(file_get_contents(__DIR__.'/../../vendor/composer/installed.json'), true);
        foreach ($installed['packages'] as $package) {
            if (str_starts_with($package['name'], 'filament/')) {
                foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                    $providers[] = $provider;
                }
            }
        }

        return [...$providers, LicenseKitServiceProvider::class, TestPanelProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set($this->settings);
    }

    /** @param array<string, mixed> $settings */
    public function bootWithSettings(array $settings): void
    {
        $this->settings = $settings;
        $this->refreshApplication();
    }
}
