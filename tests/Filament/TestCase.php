<?php

namespace LicenseKit\Tests\Filament;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\SanctumServiceProvider;
use LicenseKit\LicenseKitServiceProvider;
use LicenseKit\Tests\Core\Fixtures\ClientProtocolProvider;
use LicenseKit\Tests\Filament\Fixtures\FullPanelProvider;
use LicenseKit\Tests\Filament\Fixtures\TestPanelProvider;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    public bool $freeAccess = false;

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

        return [...$providers, LicenseKitServiceProvider::class, ClientProtocolProvider::class, TestPanelProvider::class, FullPanelProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('licensekit.user.suspended_column', 'is_suspended');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('view.paths', [__DIR__.'/../../vendor/orchestra/testbench-core/laravel/resources/views']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/laravel/sanctum/database/migrations');
    }
}
