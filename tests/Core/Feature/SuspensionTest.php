<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\CustomerLicenseSummary;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Contracts\AccessRestriction;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\User;

class HostBanRestriction implements AccessRestriction
{
    public ?Entitlement $entitlement = null;

    public function assertAllowed(Model $user, Product $product, ?Entitlement $entitlement): void
    {
        $this->entitlement = $entitlement;
        throw new LicensingException('host_banned');
    }
}

function suspensionAdministrator(): User
{
    $actor = User::factory()->create();
    Gate::define('licensekit.manage', fn ($user): bool => $user->getKey() === $actor->getKey());

    return $actor;
}

test('suspending a customer before their first purchase blocks redemption without using the key', function () {
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    Event::fake([EntitlementGranted::class]);

    $license = app(ManageEntitlements::class)->suspendCustomer($user, $plan->product, $actor, '  Chargeback  ');

    expect($license->status)->toBe(Entitlement::SUSPENDED)->and($license->expires_at)->toBeNull();
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'license suspended');
    expect(fn () => app(ClaimLicense::class)->claimFor($actor, $user, $key))->toThrow(LicensingException::class, 'license suspended');
    expect($key->fresh()->claimed_at)->toBeNull();
    Event::assertNotDispatched(EntitlementGranted::class);
    $audit = AuditEvent::where('event', 'license_status_changed')->sole();
    expect($audit->actor)->toBe('user:'.$actor->id)->and($audit->reason)->toBe('Chargeback');
});

test('suspension blocks paid and free access for that product only', function () {
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $license = Entitlement::factory()->create(['user_id' => $user->id]);
    $other = Entitlement::factory()->create(['user_id' => $user->id]);

    app(ManageEntitlements::class)->suspend($license, $actor);

    expect(fn () => app(AccessPolicy::class)->authorize($user, $license->product))->toThrow(LicensingException::class, 'license suspended');
    expect(fn () => app(AccessPolicy::class)->authorize($user, $license->product, CarbonImmutable::now()->addDay()))->toThrow(LicensingException::class, 'license suspended');
    expect(app(AccessPolicy::class)->authorize($user, $other->product)->source)->toBe('paid');
    expect(app(CustomerLicenseSummary::class)->for($license->fresh())['status'])->toBe('suspended');
});

test('a temporary suspension ends by itself at the chosen time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $license = Entitlement::factory()->create(['user_id' => $user->id, 'expires_at' => '2026-02-01 00:00:00']);

    app(ManageEntitlements::class)->suspend($license, $actor, until: CarbonImmutable::parse('2026-01-01T02:00:10+02:00'));
    expect($license->fresh()->suspended_until->toIso8601String())->toBe('2026-01-01T00:00:10+00:00');

    $this->travel(9)->seconds();
    expect(fn () => app(AccessPolicy::class)->authorize($user, $license->product))->toThrow(LicensingException::class, 'license suspended');
    $this->travel(1)->seconds();
    expect(app(AccessPolicy::class)->authorize($user, $license->product)->source)->toBe('paid');
});

test('unsuspending and restoring return a paused license to paused', function () {
    $actor = suspensionAdministrator();
    $license = Entitlement::factory()->paused(3600)->create(['user_id' => User::factory()->create()->id]);
    $tools = app(ManageEntitlements::class);

    $tools->suspend($license, $actor);
    $tools->unsuspend($license, $actor);
    expect($license->fresh()->status)->toBe(Entitlement::PAUSED);

    $tools->revoke($license, $actor);
    $tools->restore($license, $actor);
    expect($license->fresh()->status)->toBe(Entitlement::PAUSED)->and($license->fresh()->remaining_seconds)->toBe(3600);
});

test('suspension ends the product sessions but keeps the clock and other sessions', function () {
    $this->freezeTime();
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $licenses = collect([1, 2])->map(fn () => Entitlement::factory()->create(['user_id' => $user->id]));
    $desktop = app(ClientSessions::class);
    $tokens = $licenses->map(fn ($license) => $desktop->login($license->product, $user->email, 'password', desktopRequest($user)['hwid'])['token']);
    $expiry = $licenses[0]->fresh()->expires_at;

    app(ManageEntitlements::class)->suspend($licenses[0], $actor, 'Abuse');

    expect($licenses[0]->fresh()->expires_at->equalTo($expiry))->toBeTrue();
    expect(fn () => $desktop->heartbeat($licenses[0]->product, $tokens[0], desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'invalid token');
    expect(fn () => $desktop->login($licenses[0]->product, $user->email, 'password', desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'license suspended');
    $desktop->heartbeat($licenses[1]->product, $tokens[1], desktopRequest($user)['hwid']);

    app(ManageEntitlements::class)->unsuspend($licenses[0], $actor);
    $desktop->login($licenses[0]->product, $user->email, 'password', desktopRequest($user)['hwid']);
});

test('a suspension end in the past is rejected without changes', function () {
    $actor = suspensionAdministrator();
    $license = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(fn () => app(ManageEntitlements::class)->suspend($license, $actor, until: CarbonImmutable::now()->subMinute()))
        ->toThrow(LicensingException::class, 'invalid suspension end');
    expect($license->fresh()->status)->toBe(Entitlement::ACTIVE);
});

test('host restrictions reject both claim paths before mutation with optional paid history', function (bool $existing, bool $admin) {
    $actor = suspensionAdministrator();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $entitlement = $existing ? Entitlement::factory()->expired()->create(['user_id' => $user->id, 'product_id' => $plan->product_id]) : null;
    $before = $entitlement?->fresh()->getAttributes();
    $restriction = new HostBanRestriction;
    app()->instance(HostBanRestriction::class, $restriction);
    config(['licensekit.access_restrictions' => [HostBanRestriction::class]]);
    Event::fake([EntitlementGranted::class]);

    expect(fn () => $admin ? app(ClaimLicense::class)->claimFor($actor, $user, $key) : app(ClaimLicense::class)->claim($user, $key->key))
        ->toThrow(LicensingException::class, 'host banned');

    expect($restriction->entitlement?->id)->toBe($entitlement?->id);
    expect($entitlement?->fresh()->getAttributes())->toBe($before);
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', $existing ? 1 : 0);
    $this->assertDatabaseMissing('licensekit_audit_events', ['event' => 'key_claimed']);
    Event::assertNotDispatched(EntitlementGranted::class);
})->with([true, false])->with([true, false]);

test('host restrictions deny paid and free access', function (bool $paid) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    if ($paid) {
        Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    }
    config(['licensekit.access_restrictions' => [HostBanRestriction::class]]);

    expect(fn () => app(AccessPolicy::class)->authorize($user, $product, freeUntil: CarbonImmutable::now()->addHour()))
        ->toThrow(LicensingException::class, 'host banned');
    expect(fn () => app(AccessPolicy::class)->assertAllowed($user, $product))->toThrow(LicensingException::class, 'host banned');
})->with([true, false]);

test('host suspension accepts boolean timestamp and computed attributes', function (string $attribute) {
    config(['licensekit.user.suspended_column' => $attribute, 'licensekit.user.require_verified' => false]);
    $user = User::factory()->create(['is_suspended' => true]);

    expect(app(UserAccess::class)->available($user))->toBeFalse();

    $user->update(['is_suspended' => false, 'email_verified_at' => null]);
    expect(app(UserAccess::class)->available($user->fresh()))->toBeTrue();
})->with(['is_suspended', 'email_verified_at', 'licensing_suspended']);

test('setup diagnostics accept computed suspension attributes and reject missing attributes', function () {
    config(['licensekit.user.suspended_column' => 'licensing_suspended']);

    $this->artisan('licensekit:check')->expectsOutputToContain('LicenseKit installation is ready.')->assertSuccessful();

    config(['licensekit.user.suspended_column' => 'missing_suspension']);
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing user column: missing_suspension.')->assertFailed();
});
