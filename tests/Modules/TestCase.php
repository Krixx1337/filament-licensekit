<?php

namespace LicenseKit\Tests\Modules;

use LicenseKit\Tests\Core\TestCase as CoreTestCase;

class TestCase extends CoreTestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Core/Fixtures/migrations');
    }
}
