<?php

namespace LicenseKit\Tests\Modules;

use LicenseKit\Tests\Core\TestCase as CoreTestCase;

class TestCase extends CoreTestCase
{
    public bool $outages = false;

    public bool $downloads = false;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('licensekit.modules.outages', $this->outages);
        $app['config']->set('licensekit.modules.downloads', $this->downloads);
        $app['config']->set('filesystems.disks.s3', ['driver' => 's3', 'visibility' => 'private', 'bucket' => 'test', 'region' => 'us-east-1']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Core/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/laravel/sanctum/database/migrations');
    }

    public function bootModules(bool $outages, bool $downloads): void
    {
        $this->outages = $outages;
        $this->downloads = $downloads;
        $this->refreshApplication();
        $this->app['migrator']->path(__DIR__.'/../Core/Fixtures/migrations');
        $this->app['migrator']->path(__DIR__.'/../../vendor/laravel/sanctum/database/migrations');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }
}
