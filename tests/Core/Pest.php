<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\License;
use LicenseKit\Tests\Core\Fixtures\User;
use LicenseKit\Tests\Core\TestCase;

pest()->extend(TestCase::class)->in('Core/Feature');

function lockedDeviceCount(): int
{
    return License::whereNotNull('device')->count();
}
function desktopRequest(User $user, string $device = 'desktop'): array
{
    return ['login' => $user->email, 'password' => 'password', 'hwid' => $device];
}
function testAdminActor(): User
{
    Gate::define('licensekit.admin', fn (): bool => true);

    return User::factory()->create();
}
