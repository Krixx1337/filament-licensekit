<?php

use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Filament\Widgets\GetStarted;
use LicenseKit\Filament\Widgets\LicensesByStatusChart;
use LicenseKit\Filament\Widgets\LicenseStats;
use LicenseKit\Filament\Widgets\NeedsAttention;
use LicenseKit\Filament\Widgets\RecentActivity;
use LicenseKit\Filament\Widgets\RedemptionsChart;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('dashboard stats count active licenses, redemptions, stock and renewals', function () {
    adminUser();
    $plan = LicensePlan::factory()->create();
    License::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $plan->product_id, 'expires_at' => now()->addDays(30)]);
    $expiring = License::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $plan->product_id, 'expires_at' => now()->addDays(2)]);
    LicenseKey::factory()->count(3)->create(['batch_id' => LicenseKey::factory()->create()->batch_id]);
    LicenseKey::factory()->create(['redeemed_at' => now()->subDay(), 'license_id' => $expiring->id]);

    Livewire::test(LicenseStats::class, ['pageFilters' => ['period' => '7']])
        ->assertSeeInOrder(['Active licenses', '2', 'Keys redeemed', '1', 'Up from 0 in the previous 7 days', 'Unused keys', '4', 'Expiring this week', '1']);
});

test('dashboard filters narrow every widget to one product', function () {
    adminUser();
    $shown = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDay()]);
    $hidden = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDay()]);
    $filters = ['pageFilters' => ['product_id' => (string) $shown->product_id, 'period' => '30']];

    Livewire::test(NeedsAttention::class, $filters)->assertCanSeeTableRecords([$shown])->assertCanNotSeeTableRecords([$hidden]);
    Livewire::test(LicenseStats::class, $filters)->assertSeeInOrder(['Expiring this week', '1']);
    Livewire::test(RedemptionsChart::class, $filters)->assertOk();
    Livewire::test(LicensesByStatusChart::class, $filters)->assertOk();
});

test('needs attention lists expiring, just expired and suspended licenses only', function () {
    adminUser();
    $customer = fn () => User::factory()->create()->id;
    $expiring = License::factory()->create(['user_id' => $customer(), 'expires_at' => now()->addDays(3)]);
    $justExpired = License::factory()->create(['user_id' => $customer(), 'expires_at' => now()->subDay()]);
    $suspended = License::factory()->create(['user_id' => $customer(), 'status' => License::SUSPENDED, 'expires_at' => now()->addDays(40)]);
    $healthy = License::factory()->create(['user_id' => $customer(), 'expires_at' => now()->addDays(40)]);
    $longExpired = License::factory()->create(['user_id' => $customer(), 'expires_at' => now()->subDays(10)]);

    Livewire::test(NeedsAttention::class)->assertCanSeeTableRecords([$expiring, $justExpired, $suspended])
        ->assertCanNotSeeTableRecords([$healthy, $longExpired])->assertSee(['Expiring soon', 'Expired 1 day ago', 'Suspended']);
});

test('get started hides once a product, keys and an app login exist', function () {
    adminUser();
    expect(GetStarted::canView())->toBeTrue();
    Livewire::test(GetStarted::class)->assertSee(['Create a product', 'Generate keys', 'Connect your app']);

    $key = LicenseKey::factory()->create();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $key->batch->plan->product_id]);
    expect(GetStarted::canView())->toBeTrue();
    LoaderSession::create(['token_hash' => str_repeat('a', 64), 'user_id' => $license->user_id, 'product_id' => $license->product_id, 'device' => 'pc',
        'user_fingerprint' => str_repeat('b', 64), 'expires_at' => now()->addMinutes(15), 'last_seen_at' => now()]);

    expect(GetStarted::canView())->toBeFalse();
});

test('recent activity shows the latest changes', function () {
    adminUser();
    app(Keys::class)->issue(LicensePlan::factory()->create(), 1, new IssueContext(source: 'app', reference: 'order-1'));

    Livewire::test(RecentActivity::class)->assertSee('generated keys');
});
