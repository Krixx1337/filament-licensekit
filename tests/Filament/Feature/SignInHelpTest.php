<?php

use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\SignInProblems;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Resources\ActivityLog\Pages\ManageActivityLog;
use LicenseKit\Filament\Resources\Licenses\Pages\ViewLicense;
use LicenseKit\Filament\Support\PcHistory;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

function signInHelpHtml(License $license): string
{
    $page = Livewire::test(ViewLicense::class, ['record' => $license->getRouteKey()])->mountAction('sign_in_help');

    return $page->instance()->getSchema('mountedActionSchema0')->toHtml();
}

test('the license page explains why a customer cannot sign in', function () {
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'device' => 'pc-a']);
    expect(fn () => app(LicensedDevices::class)->verify($license->user, $license->product, 'pc-b'))->toThrow(LicensingException::class);

    expect(signInHelpHtml($license))->toContain('Their app would let them in until', 'Your license is active', 'Locked to another PC', PcHistory::label('pc-b'));

    $license->update(['status' => License::SUSPENDED]);
    expect(signInHelpHtml($license))->toContain('Their app would refuse: license suspended.', 'Your license is suspended. Contact support for help.');
});

test('the activity log filters sign-in problems', function () {
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'device' => 'pc-a']);
    app(SignInProblems::class)->record($license->user, $license->product, 'device_mismatch', 'pc-b');
    app(LicensedDevices::class)->adminReset($license->user, $license->product, adminUser());
    $problem = ActivityEntry::where('event', SignInProblems::EVENT)->sole();
    $reset = ActivityEntry::where('event', 'device_reset')->sole();

    Livewire::test(ManageActivityLog::class)->assertSee("couldn't sign in (locked to another PC)")
        ->filterTable('sign_in_problems')->assertCanSeeTableRecords([$problem])->assertCanNotSeeTableRecords([$reset]);
});
