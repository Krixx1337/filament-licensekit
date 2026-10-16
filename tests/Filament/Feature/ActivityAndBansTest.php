<?php

use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Resources\ActivityLog\Pages\ManageActivityLog;
use LicenseKit\Filament\Resources\DeviceBans\Pages\ManageDeviceBans;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('the activity log reads as sentences and filters by type and date', function () {
    $admin = adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    app(ManageLicenses::class)->pause($license, $admin, 'Holiday');
    app(ManageLicenses::class)->resume($license, $admin);
    $paused = ActivityEntry::where('event', 'license_paused')->sole();
    $resumed = ActivityEntry::where('event', 'license_resumed')->sole();

    Livewire::test(ManageActivityLog::class)->assertSee([$admin->email.' paused a license', 'Holiday', 'License #'.$license->id])
        ->filterTable('event', ['license_paused'])->assertCanSeeTableRecords([$paused])->assertCanNotSeeTableRecords([$resumed])
        ->resetTableFilters()
        ->filterTable('created_at', ['from' => now()->addDay()->toDateString()])->assertCanNotSeeTableRecords([$paused, $resumed]);
});

test('banned PCs list who banned them and can be unbanned with a confirmation', function () {
    $admin = adminUser();
    Livewire::test(ManageDeviceBans::class)->assertSee('No banned PCs');
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'device' => 'cheater-pc']);
    $ban = app(DeviceBans::class)->ban($license, $admin, note: 'Chargeback');

    Livewire::test(ManageDeviceBans::class)->assertSee(['cheater-pc', 'Chargeback', $admin->email, $license->product->name])
        ->callTableAction('unban', $ban)->assertNotified('PC unbanned');
    $this->assertDatabaseCount('licensekit_device_bans', 0);
});
