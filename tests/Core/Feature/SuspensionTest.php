<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CustomerLicenseSummary;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\LoaderSessions;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Events\KeyRedeemed;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Users\UserAccess;
use LicenseKit\Tests\Core\Fixtures\User;

function suspensionAdministrator(): User
{
    $actor = User::factory()->create();
    Gate::define('licensekit.admin', fn ($user): bool => $user->getKey() === $actor->getKey());

    return $actor;
}

test('suspending a customer before their first purchase blocks redemption without using the key', function () {
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'app', reference: 'sale'))->keys->first();
    Event::fake([KeyRedeemed::class]);

    $license = app(ManageLicenses::class)->suspendCustomer($user, $plan->product, $actor, '  Chargeback  ');

    expect($license->status)->toBe(License::SUSPENDED)->and($license->expires_at)->toBeNull();
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'suspended');
    expect(fn () => app(ClaimLicense::class)->claimFor($actor, $user, $key))->toThrow(LicensingException::class, 'suspended');
    expect($key->fresh()->redeemed_at)->toBeNull();
    Event::assertNotDispatched(KeyRedeemed::class);
    $audit = ActivityEntry::where('event', 'license_status_changed')->sole();
    expect($audit->actor)->toBe('user:'.$actor->id)->and($audit->meta['note'])->toBe('Chargeback');
});

test('suspension blocks paid and free access for that product only', function () {
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $other = License::factory()->create(['user_id' => $user->id]);

    app(ManageLicenses::class)->suspend($license, $actor);

    expect(fn () => app(AccessPolicy::class)->authorize($user, $license->product))->toThrow(LicensingException::class, 'suspended');
    expect(fn () => app(AccessPolicy::class)->authorize($user, $license->product))->toThrow(LicensingException::class, 'suspended');
    expect(app(AccessPolicy::class)->authorize($user, $other->product)->source)->toBe('paid');
    expect(app(CustomerLicenseSummary::class)->for($license->fresh())['status'])->toBe('suspended');
});

test('a temporary suspension ends by itself at the chosen time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, 'expires_at' => '2026-02-01 00:00:00']);

    app(ManageLicenses::class)->suspend($license, $actor, until: CarbonImmutable::parse('2026-01-01T02:00:10+02:00'));
    expect($license->fresh()->suspended_until->toIso8601String())->toBe('2026-01-01T00:00:10+00:00');

    $this->travel(9)->seconds();
    expect(fn () => app(AccessPolicy::class)->authorize($user, $license->product))->toThrow(LicensingException::class, 'suspended');
    $this->travel(1)->seconds();
    expect(app(AccessPolicy::class)->authorize($user, $license->product)->source)->toBe('paid');
});

test('unsuspending and restoring return a paused license to paused', function () {
    $actor = suspensionAdministrator();
    $license = License::factory()->paused(3600)->create(['user_id' => User::factory()->create()->id]);
    $tools = app(ManageLicenses::class);

    $tools->suspend($license, $actor);
    $tools->unsuspend($license, $actor);
    expect($license->fresh()->status)->toBe(License::PAUSED);

    $tools->revoke($license, $actor);
    $tools->restore($license, $actor);
    expect($license->fresh()->status)->toBe(License::PAUSED)->and($license->fresh()->remaining_seconds)->toBe(3600);
});

test('suspension ends the product sessions but keeps the clock and other sessions', function () {
    $this->freezeTime();
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $licenses = collect([1, 2])->map(fn () => License::factory()->create(['user_id' => $user->id]));
    $desktop = app(LoaderSessions::class);
    $tokens = $licenses->map(fn ($license) => $desktop->login($license->product, $user->email, 'password', desktopRequest($user)['hwid'])['token']);
    $expiry = $licenses[0]->fresh()->expires_at;

    app(ManageLicenses::class)->suspend($licenses[0], $actor, 'Abuse');

    expect($licenses[0]->fresh()->expires_at->equalTo($expiry))->toBeTrue();
    expect(fn () => $desktop->heartbeat($licenses[0]->product, $tokens[0], desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'invalid token');
    expect(fn () => $desktop->login($licenses[0]->product, $user->email, 'password', desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'suspended');
    $desktop->heartbeat($licenses[1]->product, $tokens[1], desktopRequest($user)['hwid']);

    app(ManageLicenses::class)->unsuspend($licenses[0], $actor);
    $desktop->login($licenses[0]->product, $user->email, 'password', desktopRequest($user)['hwid']);
});

test('a suspension end in the past is rejected without changes', function () {
    $actor = suspensionAdministrator();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(fn () => app(ManageLicenses::class)->suspend($license, $actor, until: CarbonImmutable::now()->subMinute()))
        ->toThrow(LicensingException::class, 'invalid suspension end');
    expect($license->fresh()->status)->toBe(License::ACTIVE);
});

test('host suspension accepts boolean timestamp and computed attributes', function (string $attribute) {
    config(['licensekit.user_suspended_column' => $attribute, 'licensekit.user.require_verified' => false]);
    $user = User::factory()->create(['is_suspended' => true]);

    expect(app(UserAccess::class)->available($user))->toBeFalse();

    $user->update(['is_suspended' => false, 'email_verified_at' => null]);
    expect(app(UserAccess::class)->available($user->fresh()))->toBeFalse();
})->with(['is_suspended', 'email_verified_at', 'licensing_suspended']);

test('setup diagnostics accept computed suspension attributes and reject missing attributes', function () {
    config(['licensekit.user_suspended_column' => 'licensing_suspended']);

    $this->artisan('licensekit:check')->expectsOutputToContain('LicenseKit installation is ready.')->assertSuccessful();

    config(['licensekit.user_suspended_column' => 'missing_suspension']);
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing user column: missing_suspension.')->assertFailed();
});

test('suspension cannot undo a cancellation', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, 'status' => 'revoked']);
    expect(fn () => app(ManageLicenses::class)->suspend($license, $user))->toThrow(LicensingException::class, 'cannot suspend license');
    expect($license->fresh()->status)->toBe('revoked');
});

test('unsuspend never restores a cancelled license and restore never lifts a suspension', function () {
    $actor = suspensionAdministrator();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $tools = app(ManageLicenses::class);

    $tools->revoke($license, $actor);
    expect(fn () => $tools->unsuspend($license, $actor))->toThrow(LicensingException::class, 'cannot unsuspend license');
    expect($license->fresh()->status)->toBe(License::REVOKED);

    $tools->restore($license, $actor);
    $tools->suspend($license, $actor);
    expect(fn () => $tools->restore($license, $actor))->toThrow(LicensingException::class, 'cannot restore license');
    expect($license->fresh()->status)->toBe(License::SUSPENDED);
    expect(fn () => $tools->unsuspend($license->fresh(), $actor))->not->toThrow(LicensingException::class);
    expect(fn () => $tools->unsuspend($license->fresh(), $actor))->toThrow(LicensingException::class, 'cannot unsuspend license');
});

test('a passed suspension no longer blocks staff clock actions or status filters', function () {
    $this->freezeTime();
    $actor = suspensionAdministrator();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDays(10)]);
    app(ManageLicenses::class)->suspend($license, $actor, until: CarbonImmutable::now()->addHour());

    expect(License::effectiveStatus(License::SUSPENDED)->pluck('id')->all())->toBe([$license->id]);
    $this->travel(2)->hours();
    expect(License::effectiveStatus(License::SUSPENDED)->count())->toBe(0)
        ->and(License::effectiveStatus(License::ACTIVE)->pluck('id')->all())->toBe([$license->id]);

    app(ManageLicenses::class)->pause($license, $actor);

    expect($license->fresh())->status->toBe(License::PAUSED)->suspended_until->toBeNull();
    expect(License::effectiveStatus(License::PAUSED)->pluck('id')->all())->toBe([$license->id]);
});
