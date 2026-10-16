<?php

use Filament\Notifications\Notification;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Filament\Resources\Licenses\Pages\ViewLicense;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('license tabs count and filter by status', function () {
    adminUser();
    $customer = fn () => User::factory()->create()->id;
    $active = License::factory()->create(['user_id' => $customer(), 'expires_at' => now()->addDays(30)]);
    $expiring = License::factory()->create(['user_id' => $customer(), 'expires_at' => now()->addDays(2)]);
    $expired = License::factory()->create(['user_id' => $customer(), 'expires_at' => now()->subDay()]);
    $cancelled = License::factory()->create(['user_id' => $customer(), 'status' => License::REVOKED]);

    $page = Livewire::test(ManageLicenses::class);
    expect(collect($page->instance()->getTabs())->map(fn ($tab) => $tab->getBadge())->only(['all', 'active', 'expiring', 'expired', 'cancelled'])->all())
        ->toBe(['all' => '4', 'active' => '2', 'expiring' => '1', 'expired' => '1', 'cancelled' => '1']);
    $page->set('activeTab', 'expiring')->assertCanSeeTableRecords([$expiring])->assertCanNotSeeTableRecords([$active, $expired, $cancelled])
        ->set('activeTab', 'cancelled')->assertCanSeeTableRecords([$cancelled])->assertCanNotSeeTableRecords([$active]);
});

test('license page shows overview, PC lock, keys and a shared PC warning', function () {
    adminUser();
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $license = License::factory()->create(['user_id' => $owner->id, 'device' => 'shared-pc', 'device_bound_at' => now(), 'note' => 'VIP customer']);
    License::factory()->create(['user_id' => User::factory()->create(['email' => 'alt@example.com'])->id, 'device' => 'shared-pc']);
    $key = LicenseKey::factory()->create(['license_id' => $license->id, 'redeemed_at' => now()]);

    Livewire::test(ViewLicense::class, ['record' => $license->getRouteKey()])
        ->assertSee(['owner@example.com · '.$license->product->name, 'Overview', 'Locked to a PC', 'Once every 7 days', 'VIP customer', $key->key])
        ->assertSee('Shared PC warning')->assertSee('alt@example.com');
});

test('license page actions refresh the page and confirm with the customer name', function () {
    adminUser();
    $owner = User::factory()->create(['email' => 'buyer@example.com']);
    $license = License::factory()->create(['user_id' => $owner->id, 'expires_at' => now()->addDays(10)]);

    Livewire::test(ViewLicense::class, ['record' => $license->getRouteKey()])
        ->callAction('extend', data: ['duration' => 5, 'unit' => 'days'])->assertHasNoActionErrors()
        ->assertNotified(Notification::make()->title('Added 5 days to buyer@example.com · '.$license->product->name)->success())
        ->assertSee('15 days left')
        ->callAction('pause')->assertHasNoActionErrors()->assertSee('Paused · 15 days saved')
        ->assertActionVisible('resume')->assertActionHidden('pause');
});

test('the manage menu only offers actions that apply', function () {
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'status' => License::REVOKED]);

    Livewire::test(ManageLicenses::class)->assertTableActionVisible('restore', $license)
        ->assertTableActionHidden('cancel', $license)->assertTableActionHidden('reset_hwid', $license)->assertTableActionHidden('resume', $license);
});

test('an empty license list explains where licenses come from', function () {
    adminUser();

    Livewire::test(ManageLicenses::class)->assertSee(['No licenses yet', 'A license appears when a customer redeems a key, or when you give one.']);
});
