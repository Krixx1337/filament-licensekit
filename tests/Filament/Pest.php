<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Tests\Filament\Fixtures\User;
use LicenseKit\Tests\Filament\TestCase;

uses(TestCase::class)->in('Filament/Feature');

function adminUser(): User
{
    $user = User::factory()->create();
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->getKey() === $user->getKey());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    test()->actingAs($user, 'staff');

    return $user;
}
