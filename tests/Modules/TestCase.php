<?php

namespace LicenseKit\Tests\Modules;

use LicenseKit\Tests\Core\TestCase as CoreTestCase;

class TestCase extends CoreTestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Core/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/laravel/sanctum/database/migrations');
    }
}
