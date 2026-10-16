<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Tests\Integration\Fixtures\User;
use LicenseKit\Tests\Integration\MySqlTestCase;

pest()->extend(MySqlTestCase::class)->in('Integration');

function integrationAdmin(): User
{
    $admin = User::factory()->create();
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->getKey() === $admin->getKey());

    return $admin;
}
