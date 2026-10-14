<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\CustomerLicenses;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\LicensingBans;
use LicenseKit\Core\Actions\ManageLicensingBans;
use LicenseKit\Core\Contracts\AccessRestriction;
use LicenseKit\Core\Events\EntitlementAccessInvalidated;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\UlidUser;
use LicenseKit\Tests\Core\Fixtures\User;
use LicenseKit\Tests\Core\Fixtures\UuidUser;

class HostBanRestriction implements AccessRestriction
{
    public ?Entitlement $entitlement = null;

    public function assertAllowed(Model $user, Product $product, ?Entitlement $entitlement): void
    {
        $this->entitlement = $entitlement;
        throw new LicensingException('host_banned');
    }
}

function banAdministrator(): User
{
    $actor = User::factory()->create();
    Gate::define('licensekit.manage', fn ($user): bool => $user->getKey() === $actor->getKey());

    return $actor;
}

test('ban before first entitlement denies valid claims with 403 and no grants', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $ban = app(ManageLicensingBans::class)->create($user, $plan->product, '  Private administrator reason  ', $actor);
    Event::fake([EntitlementGranted::class]);
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'license banned');
    expect(fn () => app(ClaimLicense::class)->claimFor($actor, $user, $key))->toThrow(LicensingException::class, 'license banned');
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
    Event::assertNotDispatched(EntitlementGranted::class);
    expect($ban->reason)->toBe('Private administrator reason');
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'licensing_ban_created', 'actor' => 'user:'.$actor->id]);
});

test('product bans isolate users and products and preserve issuance and claim error order', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    app(ManageLicensingBans::class)->create($user, $plan->product, 'Abuse', $actor);
    $batch = app(CreateBatch::class)->create($plan, 2, 'sale', 'test');
    app(ClaimLicense::class)->claim($other, $batch->keys[0]->key);
    $otherPlan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($otherPlan, 1, 'other', 'test')->keys->first();
    app(ClaimLicense::class)->claim($user, $key->key);
    expect(fn () => app(ClaimLicense::class)->claim($user, $batch->keys[0]->key))->toThrow(LicensingException::class, 'key already claimed');
    expect(fn () => app(ClaimLicense::class)->claim($user, 'INVALID'))->toThrow(LicensingException::class, 'invalid key');
    $user->update(['is_suspended' => true]);
    expect(fn () => app(AccessPolicy::class)->authorize($user, $plan->product))->toThrow(LicensingException::class, 'user unavailable');
    $this->assertDatabaseCount('licensekit_entitlements', 2);
});

test('duplicate active product gives 409 while repeated lifting preserves other product bans', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $manage = app(ManageLicensingBans::class);
    $otherProduct = Product::factory()->create();
    $otherBan = $manage->create($user, $otherProduct, 'Other', $actor);
    $scoped = $manage->create($user, $product, 'One', $actor);
    foreach ([$otherProduct, $product] as $scope) {
        try {
            $manage->create($user, $scope, 'Duplicate', $actor);
            $this->fail('Duplicate ban accepted.');
        } catch (LicensingException $exception) {
            expect($exception->error)->toBe('ban_already_active')->and($exception->status)->toBe(409);
        }
    }
    $lifted = $manage->lift($otherBan, '  Appeal accepted  ', $actor);
    $again = $manage->lift($otherBan, 'Different reason', $actor);
    expect($again->getAttributes())->toBe($lifted->getAttributes());
    expect($lifted->lift_reason)->toBe('Appeal accepted')->and($lifted->lifted_by)->toBe('user:'.$actor->id);
    expect(AuditEvent::where('event', 'licensing_ban_lifted')->count())->toBe(1);
    expect(fn () => app(LicensingBans::class)->assertAllowed($user, $product))->toThrow(LicensingException::class);
    $manage->lift($scoped, 'Resolved', $actor);
    app(LicensingBans::class)->assertAllowed($user, $product);
    $manage->create($user, $product, 'New terms', $actor);
    $this->assertDatabaseCount('licensekit_licensing_bans', 3);
});

test('temporary ban expires at exact UTC boundary and permits new scope without deleting history', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $actor = banAdministrator();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $ban = app(ManageLicensingBans::class)->create($user, $product, 'Temporary', $actor, CarbonImmutable::parse('2026-01-01T02:00:10+02:00'));
    expect($ban->fresh()->expires_at->toIso8601String())->toBe('2026-01-01T00:00:10+00:00');
    $this->travel(9)->seconds();
    expect(fn () => app(LicensingBans::class)->assertAllowed($user, $product))->toThrow(LicensingException::class);
    $this->travel(1)->seconds();
    app(LicensingBans::class)->assertAllowed($user, $product);
    expect($ban->fresh()->status())->toBe('Expired');
    app(ManageLicensingBans::class)->create($user, $product, 'New ban', $actor);
    app(ManageLicensingBans::class)->lift($ban, 'History reviewed', $actor);
    expect(fn () => app(LicensingBans::class)->assertAllowed($user, $product))->toThrow(LicensingException::class);
    $this->assertDatabaseCount('licensekit_licensing_bans', 2);
});

test('blank and oversized create and lift reasons fail without mutation', function (string $reason) {
    $actor = banAdministrator();
    $user = User::factory()->create();
    expect(fn () => app(ManageLicensingBans::class)->create($user, Product::factory()->create(), $reason, $actor))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('licensekit_licensing_bans', 0);
    $ban = app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Valid', $actor);
    expect(fn () => app(ManageLicensingBans::class)->lift($ban, $reason, $actor))->toThrow(ValidationException::class);
    expect($ban->fresh()->lifted_at)->toBeNull();
    expect(AuditEvent::where('event', 'licensing_ban_lifted')->count())->toBe(0);
})->with(['', '  ', str_repeat('x', 2001)]);

test('past and present expiry fail before writing bans', function (int $seconds) {
    $this->freezeTime();
    $actor = banAdministrator();
    $user = User::factory()->create();
    expect(fn () => app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Reason', $actor, now()->addSeconds($seconds)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('licensekit_licensing_bans', 0);
})->with([-1, 0]);

test('management rechecks fresh host actor permission and availability', function (string $condition) {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $ban = app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Reason', $actor);
    match ($condition) {
        'permission' => Gate::define('licensekit.manage', fn () => false),
        'suspension' => $actor->update(['is_suspended' => true]),
        'verification' => $actor->update(['email_verified_at' => null]),
        'deleted' => $actor->delete(),
    };
    expect(fn () => app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Reason', $actor))->toThrow(LicensingException::class, 'ban forbidden');
    expect(fn () => app(ManageLicensingBans::class)->lift($ban, 'Reason', $actor))->toThrow(LicensingException::class, 'ban forbidden');
    expect($ban->fresh()->lifted_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_licensing_bans', 1);
})->with(['permission', 'suspension', 'verification', 'deleted']);

test('bans clear every affected default session while preserving clocks bindings and unrelated tokens', function () {
    $this->freezeTime();
    $actor = banAdministrator();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $entitlements = collect([1, 2])->map(fn () => Entitlement::factory()->create(['user_id' => $user->id]));
    $unrelated = Entitlement::factory()->create(['user_id' => $other->id]);
    $desktop = app(ClientSessions::class);
    $tokens = $entitlements->map(fn ($entitlement) => $desktop->login($entitlement->product, $user->email, 'password', desktopRequest($user)['hwid'])['token']);
    $otherToken = $desktop->login($unrelated->product, $other->email, 'password', desktopRequest($other)['hwid'])['token'];
    $integrationToken = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create()->createToken('unrelated', ['custom'])->accessToken;
    $before = $entitlements->map(fn ($entitlement) => $entitlement->fresh()->getAttributes());
    $bindings = DB::table('licensekit_device_bindings')->get()->toArray();
    $ban = app(ManageLicensingBans::class)->create($user, $entitlements[0]->product, 'Abuse', $actor);
    expect($entitlements->map(fn ($entitlement) => $entitlement->fresh()->getAttributes())->all())->toBe($before->all());
    expect(DB::table('licensekit_device_bindings')->get()->toArray())->toEqual($bindings);
    expect($integrationToken->fresh())->not->toBeNull();
    expect(fn () => $desktop->heartbeat($entitlements[0]->product, $tokens[0], desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'invalid token');
    expect(fn () => $desktop->login($entitlements[0]->product, $user->email, 'password', desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'license banned');
    $desktop->heartbeat($entitlements[1]->product, $tokens[1], desktopRequest($user)['hwid']);
    $desktop->heartbeat($unrelated->product, $otherToken, desktopRequest($other)['hwid']);
    app(ManageLicensingBans::class)->lift($ban, 'Appeal', $actor);
    expect(fn () => $desktop->heartbeat($entitlements[0]->product, $tokens[0], desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'invalid token');
    $desktop->login($entitlements[0]->product, $user->email, 'password', desktopRequest($user)['hwid']);
});

test('denied login before first binding creates no token and exposes no ban details', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    app(ManageLicensingBans::class)->create($user, $entitlement->product, 'Secret', $actor);
    $this->postJson(route('licensekit.desktop.login', $entitlement->product->slug), desktopRequest($user))->assertForbidden()
        ->assertExactJson(['error' => 'license_banned', 'message' => 'license banned']);
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(app(CustomerLicenses::class)->paginate($user)->items()[0])->not->toHaveKey('bans');
});

test('expiry permits fresh authentication without reviving old tokens', function () {
    $this->freezeTime();
    $actor = banAdministrator();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $desktop = app(ClientSessions::class);
    $old = $desktop->login($entitlement->product, $user->email, 'password', desktopRequest($user)['hwid'])['token'];
    app(ManageLicensingBans::class)->create($user, $entitlement->product, 'Temporary', $actor, now()->addMinute());
    $this->travel(1)->minutes();
    expect(fn () => $desktop->heartbeat($entitlement->product, $old, desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'invalid token');
    expect($desktop->login($entitlement->product, $user->email, 'password', desktopRequest($user)['hwid'])['token'])->not->toBe($old);
});

test('ban failures roll back history audits and session invalidation', function (string $failure) {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $desktop = app(ClientSessions::class);
    $token = $desktop->login($entitlement->product, $user->email, 'password', desktopRequest($user)['hwid'])['token'];
    if ($failure === 'audit') {
        DB::statement("CREATE TRIGGER reject_ban_audit BEFORE INSERT ON licensekit_audit_events WHEN NEW.event = 'licensing_ban_created' BEGIN SELECT RAISE(FAIL, 'Audit unavailable'); END");
    } else {
        Event::listen(EntitlementAccessInvalidated::class, fn () => throw new RuntimeException('Listener unavailable'));
    }
    expect(fn () => app(ManageLicensingBans::class)->create($user, $entitlement->product, 'Abuse', $actor))->toThrow($failure === 'audit' ? QueryException::class : RuntimeException::class);
    $this->assertDatabaseCount('licensekit_licensing_bans', 0);
    $this->assertDatabaseCount('client_sessions', 1);
    $desktop->heartbeat($entitlement->product, $token, desktopRequest($user)['hwid']);
    expect(AuditEvent::where('event', 'licensing_ban_created')->count())->toBe(0);
})->with(['audit', 'listener']);

test('failed lift audit rolls back lifting', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $ban = app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Abuse', $actor);
    DB::statement("CREATE TRIGGER reject_lift_audit BEFORE INSERT ON licensekit_audit_events WHEN NEW.event = 'licensing_ban_lifted' BEGIN SELECT RAISE(FAIL, 'Audit unavailable'); END");
    expect(fn () => app(ManageLicensingBans::class)->lift($ban, 'Appeal', $actor))->toThrow(QueryException::class);
    expect($ban->fresh()->lifted_at)->toBeNull();
});

test('ban history detaches after user deletion and still protects product history', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $ban = app(ManageLicensingBans::class)->create($user, $product, 'Abuse', $actor);
    app(ManageLicensingBans::class)->lift($ban, 'Appeal', $actor);
    $user->delete();
    expect($ban->fresh()->user_id)->toBeNull()->and($ban->fresh()->user)->toBeNull();
    expect(fn () => $product->delete())->toThrow(QueryException::class);
    $this->assertDatabaseCount('licensekit_licensing_bans', 1);
});

test('bans preserve customer HWID resets and other restrictions after lifting', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    app(ClientSessions::class)->login($entitlement->product, $user->email, 'password', desktopRequest($user)['hwid']);
    $ban = app(ManageLicensingBans::class)->create($user, $entitlement->product, 'Abuse', $actor);
    app(LicensedDevices::class)->reset($entitlement, $user);
    expect($entitlement->fresh()->binding->hwid)->toBeNull();
    expect(fn () => app(AccessPolicy::class)->authorize($user, $entitlement->product))->toThrow(LicensingException::class, 'license banned');
    $entitlement->update(['is_revoked' => true]);
    app(ManageLicensingBans::class)->lift($ban, 'Appeal', $actor);
    expect(fn () => app(AccessPolicy::class)->authorize($user, $entitlement->product))->toThrow(LicensingException::class, 'license unavailable');
});

test('configured UUID and ULID users retain ban scope and audit identities', function (string $model) {
    config(['auth.providers.users.model' => $model]);
    DB::connection()->rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    DB::connection()->beginTransaction();
    $actor = $model::create(User::factory()->make()->getAttributes());
    $user = $model::create(User::factory()->make()->getAttributes());
    Gate::define('licensekit.manage', fn ($candidate): bool => $candidate->getKey() === $actor->getKey());
    $product = Product::factory()->create();
    $ban = app(ManageLicensingBans::class)->create($user, $product, 'Abuse', $actor);
    expect($ban->fresh()->user_id)->toBe($user->getKey());
    expect($ban->fresh()->user)->toBeInstanceOf($model);
    expect(fn () => app(LicensingBans::class)->assertAllowed($user, $product))->toThrow(LicensingException::class);
    app(ManageLicensingBans::class)->lift($ban, 'Appeal', $actor);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'licensing_ban_lifted', 'actor' => 'user:'.$actor->getKey()]);
})->with([[UuidUser::class], [UlidUser::class]]);

test('installation diagnostics require ban table even when disabled', function (bool $enabled) {
    config(['licensekit.features.licensing_bans' => $enabled]);
    Schema::drop('licensekit_licensing_bans');
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing table: licensekit_licensing_bans.')->assertFailed();
})->with([true, false]);

test('ban terms and lifted history are immutable through model writes', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $ban = app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Abuse', $actor);
    expect(fn () => $ban->update(['reason' => 'Rewritten']))->toThrow(LicensingException::class, 'ban is immutable');
    expect(fn () => $ban->fresh()->update(['product_id' => Product::factory()->create()->id]))->toThrow(LicensingException::class, 'ban is immutable');
    app(ManageLicensingBans::class)->lift($ban->fresh(), 'Appeal', $actor);
    expect(fn () => $ban->fresh()->update(['lift_reason' => 'Rewritten']))->toThrow(LicensingException::class, 'ban is immutable');
    expect(fn () => $ban->fresh()->delete())->toThrow(LicensingException::class, 'ban history required');
    expect($ban->fresh()->reason)->toBe('Abuse')->and($ban->fresh()->lift_reason)->toBe('Appeal');
});

test('expiry rounded to storage precision must still be future', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z')->setMicrosecond(100000));
    $actor = banAdministrator();
    $user = User::factory()->create();
    expect(fn () => app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Temporary', $actor, now()->addMicroseconds(100000)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('licensekit_licensing_bans', 0);
});

test('disabled bans retain history skip queries and reject management then enforce on reenable', function () {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $desktop = app(ClientSessions::class);
    $old = $desktop->login($entitlement->product, $user->email, 'password', desktopRequest($user)['hwid'])['token'];
    $ban = app(ManageLicensingBans::class)->create($user, $entitlement->product, 'Abuse', $actor);
    config(['licensekit.features.licensing_bans' => false]);
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    app(LicensingBans::class)->assertAllowed($user, $entitlement->product);

    expect($connection->getQueryLog())->toBe([]);
    $connection->disableQueryLog();
    app(AccessPolicy::class)->authorize($user, $entitlement->product);
    $plan = LicensePlan::factory()->create(['product_id' => $entitlement->product_id]);
    $key = app(CreateBatch::class)->create($plan, 1, 'disabled-ban', 'test')->keys->first();
    app(ClaimLicense::class)->claim($user, $key->key);
    expect($key->fresh()->claimed_at)->not->toBeNull();
    expect(fn () => $desktop->heartbeat($entitlement->product, $old, desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'invalid token');
    foreach ([fn () => app(ManageLicensingBans::class)->create($user, $entitlement->product, 'Again', $actor),
        fn () => app(ManageLicensingBans::class)->lift($ban, 'Appeal', $actor)] as $operation) {
        try {
            $operation();
            $this->fail('Disabled ban management accepted.');
        } catch (LicensingException $exception) {
            expect($exception->error)->toBe('licensing_bans_disabled')->and($exception->status)->toBe(403);
        }
    }
    expect($ban->fresh()->lifted_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_licensing_bans', 1);
    config(['licensekit.features.licensing_bans' => true]);
    expect(fn () => app(AccessPolicy::class)->authorize($user, $entitlement->product))->toThrow(LicensingException::class, 'license banned');
});

test('host restrictions reject both claim paths before mutation with optional paid history', function (bool $enabled, bool $existing, bool $admin) {
    $actor = banAdministrator();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $entitlement = $existing ? Entitlement::factory()->expired()->create(['user_id' => $user->id, 'product_id' => $plan->product_id]) : null;
    $before = $entitlement?->fresh()->getAttributes();
    $restriction = new HostBanRestriction;
    app()->instance(HostBanRestriction::class, $restriction);
    config(['licensekit.features.licensing_bans' => $enabled, 'licensekit.access_restrictions' => [HostBanRestriction::class]]);
    Event::fake([EntitlementGranted::class]);

    expect(fn () => $admin ? app(ClaimLicense::class)->claimFor($actor, $user, $key) : app(ClaimLicense::class)->claim($user, $key->key))
        ->toThrow(LicensingException::class, 'host banned');

    expect($restriction->entitlement?->id)->toBe($entitlement?->id);
    expect($entitlement?->fresh()->getAttributes())->toBe($before);
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', $existing ? 1 : 0);
    $this->assertDatabaseMissing('licensekit_audit_events', ['event' => 'key_claimed']);
    Event::assertNotDispatched(EntitlementGranted::class);
})->with([true, false])->with([true, false])->with([true, false]);

test('host restrictions deny paid and free access with built in bans disabled or enabled', function (bool $enabled, bool $paid) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    if ($paid) {
        Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    }
    config(['licensekit.features.licensing_bans' => $enabled, 'licensekit.access_restrictions' => [HostBanRestriction::class]]);

    expect(fn () => app(AccessPolicy::class)->authorize($user, $product, freeUntil: CarbonImmutable::now()->addHour()))
        ->toThrow(LicensingException::class, 'host banned');
    expect(fn () => app(AccessPolicy::class)->assertAllowed($user, $product))->toThrow(LicensingException::class, 'host banned');
})->with([true, false])->with([true, false]);

test('host suspension accepts boolean timestamp and computed attributes independent of built in bans', function (bool $enabled, string $attribute) {
    config(['licensekit.features.licensing_bans' => $enabled, 'licensekit.user.suspended_column' => $attribute, 'licensekit.user.require_verified' => false]);
    $user = User::factory()->create(['is_suspended' => true]);

    expect(app(UserAccess::class)->available($user))->toBeFalse();

    $user->update(['is_suspended' => false, 'email_verified_at' => null]);
    expect(app(UserAccess::class)->available($user->fresh()))->toBeTrue();
})->with([true, false])->with(['is_suspended', 'email_verified_at', 'licensing_suspended']);

test('setup diagnostics accept computed suspension attributes and reject missing attributes', function () {
    config(['licensekit.user.suspended_column' => 'licensing_suspended']);

    $this->artisan('licensekit:check')->expectsOutputToContain('LicenseKit installation is ready.')->assertSuccessful();

    config(['licensekit.user.suspended_column' => 'missing_suspension']);
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing user column: missing_suspension.')->assertFailed();
});
