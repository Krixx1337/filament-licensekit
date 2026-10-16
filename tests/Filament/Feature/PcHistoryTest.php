<?php

use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Resources\ActivityLog\Pages\ManageActivityLog;
use LicenseKit\Filament\Resources\Licenses\Pages\ViewLicense;
use LicenseKit\Filament\Support\PcHistory;
use LicenseKit\Filament\Widgets\NeedsAttention;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

function pcHistoryLicense(): License
{
    $customer = User::factory()->create(['email' => 'mover@example.com']);

    return License::factory()->create(['user_id' => $customer->id, 'expires_at' => now()->addDays(30)]);
}

test('the first PC lock is logged once', function () {
    $license = pcHistoryLicense();
    $devices = app(LicensedDevices::class);

    $devices->bind($license->user, $license->product, 'first-pc');
    $devices->bind($license->user, $license->product, 'first-pc');

    $entry = ActivityEntry::where('event', 'device_locked')->sole();
    expect($entry->meta['after']['device'])->toBe('first-pc')->and($entry->meta['actor_label'])->toBe('mover@example.com');
});

test('the license page lists every lock, move and reset with short PC names', function () {
    $admin = adminUser();
    $license = pcHistoryLicense();
    $license->product->update(['device_move_cooldown_days' => 0]);
    $devices = app(LicensedDevices::class);
    $devices->bind($license->user, $license->product, 'first-pc');
    $devices->moveDevice($license->user, $license->product, 'second-pc');
    $devices->adminReset($license->user, $license->product, $admin, 'New motherboard');

    Livewire::test(ViewLicense::class, ['record' => $license->getRouteKey()])
        ->assertSee(['PC history', 'Locked on first login', 'Moved by customer', 'Reset by staff', 'New motherboard'])
        ->assertSee(PcHistory::label('first-pc').' → '.PcHistory::label('second-pc'))
        ->assertSee(PcHistory::label('second-pc').' → unlocked')
        ->assertDontSee('Changes PC often');
    expect(PcHistory::label('first-pc'))->toMatch('/^PC [0-9A-F]{6}$/')->not->toContain('first');
});

test('three PC changes in 30 days are flagged on the license and the dashboard', function () {
    $admin = adminUser();
    $license = pcHistoryLicense();
    $calm = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDays(30), 'device' => 'calm-pc']);
    $devices = app(LicensedDevices::class);
    foreach (['a', 'b', 'c'] as $pc) {
        $devices->bind($license->user, $license->product, $pc);
        $devices->adminReset($license->user, $license->product, $admin);
    }
    $this->travel(31)->days();
    expect(PcHistory::recentChanges($license))->toBe(0);
    $this->travelBack();

    Livewire::test(ViewLicense::class, ['record' => $license->getRouteKey()])->assertSee(['Changes PC often', '3 moves or resets in the last 30 days.']);
    Livewire::test(NeedsAttention::class)->assertCanSeeTableRecords([$license])->assertCanNotSeeTableRecords([$calm])
        ->assertSee(['Changes PC often', '3 PC changes in 30 days']);
});

test('the activity log can show PC changes only', function () {
    $admin = adminUser();
    $license = pcHistoryLicense();
    app(LicensedDevices::class)->bind($license->user, $license->product, 'first-pc');
    app(LicensedDevices::class)->adminReset($license->user, $license->product, $admin);
    app(ManageLicenses::class)->updateNote($license, $admin, 'VIP');
    $pcEntries = ActivityEntry::whereIn('event', PcHistory::EVENTS)->get();
    $note = ActivityEntry::where('event', 'license_note_updated')->sole();

    Livewire::test(ManageActivityLog::class)->filterTable('pc_changes')
        ->assertCanSeeTableRecords($pcEntries)->assertCanNotSeeTableRecords([$note]);
});
