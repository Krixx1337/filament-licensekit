<?php

namespace LicenseKit\Tests\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LicenseKit\Core\Actions\ResponseSigning;
use LicenseKit\LicenseKitServiceProvider;
use LicenseKit\Tests\Core\Fixtures\User;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    public bool $desktop = true;

    public bool $clientFixture = true;

    public bool $freeAccess = false;

    public bool $management = false;

    /** @var array<string, mixed> */
    public array $routingSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(ResponseSigning::class)->install();
    }

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, LicenseKitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('licensekit.user_suspended_column', 'is_suspended');
        $app['config']->set('host_client.enabled', $this->desktop);
        $app['config']->set('licensekit.store_api_enabled', $this->management);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set($this->routingSettings);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
