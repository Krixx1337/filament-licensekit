<?php

use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Navigation;
use LicenseKit\Filament\Pages\ConnectYourApp;
use LicenseKit\Filament\Pages\Dashboard;
use LicenseKit\Filament\Pages\TeamAccess;
use LicenseKit\Filament\Resources\ActivityLog\ActivityEntryResource;
use LicenseKit\Filament\Resources\ApiConnections\ApiConnectionResource;
use LicenseKit\Filament\Resources\Batches\BatchResource;
use LicenseKit\Filament\Resources\DeviceBans\DeviceBanResource;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Resources\Products\ProductResource;
use LicenseKit\Filament\Support\TimeLeft;
use LicenseKit\Tests\Filament\Fixtures\User;

test('pages sit in three flat navigation groups', function () {
    $groups = [
        Navigation::LICENSING => [Dashboard::class, LicenseResource::class, LicenseKeyResource::class, BatchResource::class, ProductResource::class],
        Navigation::PROTECTION => [DeviceBanResource::class, ActivityEntryResource::class],
        Navigation::SETUP => [ConnectYourApp::class, ApiConnectionResource::class, TeamAccess::class],
    ];

    foreach ($groups as $group => $classes) {
        foreach ($classes as $class) {
            expect($class::getNavigationGroup())->toBe($group)->and($class::getNavigationParentItem())->toBeNull();
        }
    }
});

test('license states and time left use plain words', function () {
    $this->freezeTime();
    $make = fn (array $attributes) => License::factory()->make($attributes);

    expect(LicenseState::for($make(['expires_at' => now()->addDays(30)])))->toBe(LicenseState::Active)
        ->and(TimeLeft::for($make(['expires_at' => now()->addDays(30)])))->toBe('30 days left')
        ->and(LicenseState::for($make(['expires_at' => now()->addDays(2)])))->toBe(LicenseState::ExpiringSoon)
        ->and(TimeLeft::for($make(['expires_at' => now()->addHours(5)])))->toBe('5 hours left')
        ->and(LicenseState::for($make(['expires_at' => now()->subDays(3)])))->toBe(LicenseState::Expired)
        ->and(TimeLeft::for($make(['expires_at' => now()->subDays(3)])))->toBe('Expired 3 days ago')
        ->and(TimeLeft::for($make(['is_lifetime' => true, 'expires_at' => null])))->toBe('Never expires')
        ->and(TimeLeft::for($make(['status' => License::PAUSED, 'paused_at' => now(), 'expires_at' => null, 'remaining_seconds' => 86400])))->toBe('Paused · 1 day saved')
        ->and(LicenseState::for($make(['status' => License::REVOKED])))->toBe(LicenseState::Cancelled)
        ->and(LicenseState::for($make(['expires_at' => null])))->toBe(LicenseState::NoTimeYet);

    $free = Product::factory()->create(['free_from' => now()->subHour(), 'free_until' => now()->addDay()]);
    expect(LicenseState::for($make(['product_id' => $free->id, 'expires_at' => null])))->toBe(LicenseState::FreeAccess);
});

test('licenses badge counts licenses expiring this week and search shows their status', function () {
    adminUser();
    $soon = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDays(3)]);
    License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDays(30)]);
    License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->subDay()]);

    expect(LicenseResource::getNavigationBadge())->toBe('1')->and(LicenseResource::getNavigationBadgeColor())->toBe('warning');
    expect(LicenseResource::getGlobalSearchResultDetails($soon))->toBe(['Status' => 'Expiring soon', 'Time left' => '3 days left']);

    $soon->update(['expires_at' => now()->addDays(30)]);
    expect(LicenseResource::getNavigationBadge())->toBeNull();
});
