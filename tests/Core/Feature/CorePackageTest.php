<?php

use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\CustomerLicenses;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Contracts\AccessRestriction;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Observers\UserObserver;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\ClientUserObserver;
use LicenseKit\Tests\Core\Fixtures\TokenUser;
use LicenseKit\Tests\Core\Fixtures\UlidUser;
use LicenseKit\Tests\Core\Fixtures\User;
use LicenseKit\Tests\Core\Fixtures\UuidUser;

class MaintenanceRestriction implements AccessRestriction
{
    public function assertAllowed(Model $user, Product $product, ?Entitlement $entitlement): void
    {
        throw new LicensingException('service_unavailable');
    }
}
class BrokenRestriction implements AccessRestriction
{
    public function assertAllowed(Model $user, Product $product, ?Entitlement $entitlement): void
    {
        throw new RuntimeException('restriction failed');
    }
}

test('package installs with Filament and without host auth scaffolding or enabled modules', function () {
    expect(class_exists(Fortify::class))->toBeFalse();
    expect(class_exists(Panel::class))->toBeTrue();
    expect(class_exists(Inertia::class))->toBeFalse();
    expect(Schema::hasColumn('users', 'username'))->toBeFalse();
    expect(Schema::hasColumn('users', 'is_admin'))->toBeFalse();
    expect(Schema::hasTable('outages'))->toBeFalse();
    expect(Schema::hasTable('licensekit_free_access_windows'))->toBeFalse();
    expect(Schema::hasTable('licensekit_products'))->toBeTrue();
});

test('restriction denies login without creating binding session or token', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    config(['licensekit.access_restrictions' => [MaintenanceRestriction::class]]);
    $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->assertForbidden()->assertJsonPath('error', 'service_unavailable');
    expect(lockedDeviceCount())->toBe(0);
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('restriction blocks heartbeat without extending expiry', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->assertOk()->json('data.token');
    $expiry = PersonalAccessToken::first()->expires_at->toIso8601String();
    $this->travel(5)->minutes();
    config(['licensekit.access_restrictions' => [MaintenanceRestriction::class]]);
    $this->withToken($token)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user))->assertForbidden();
    expect(PersonalAccessToken::first()->expires_at->toIso8601String())->toBe($expiry);
});

test('shared restrictions run even without an eligible paid grant', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->expired()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    config(['licensekit.access_restrictions' => [MaintenanceRestriction::class]]);
    $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->assertForbidden()->assertJsonPath('error', 'service_unavailable');
});

test('restriction exceptions roll back login and fail closed', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    config(['licensekit.access_restrictions' => [BrokenRestriction::class]]);
    expect(fn () => app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid']))->toThrow(RuntimeException::class, 'restriction failed');
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('grant extension failure rolls back claimed key entitlement and audit', function () {
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $audits = AuditEvent::count();
    Event::listen(EntitlementGranted::class, fn () => throw new RuntimeException('grant failed'));
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(RuntimeException::class, 'grant failed');
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
    expect(AuditEvent::count())->toBe($audits);
});

test('host user security updates preserve unrelated host tokens', function () {
    config(['auth.providers.users.model' => TokenUser::class]);
    TokenUser::observe(UserObserver::class);
    TokenUser::observe(ClientUserObserver::class);
    $user = TokenUser::create(['email' => 'user@example.com', 'password' => 'password', 'email_verified_at' => now()]);
    $personal = $user->createToken('host-api', ['*'])->accessToken;
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(ClientSessions::class)->login($product, $user->email, 'password', 'pc');
    $user->update(['password' => 'new-password']);
    $this->assertDatabaseCount('client_sessions', 0);
    expect($personal->fresh())->not->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('bulk user password changes are caught by session fingerprint', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->json('data.token');
    User::whereKey($user->id)->update(['password' => password_hash('changed', PASSWORD_BCRYPT)]);
    $this->withToken($token)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user))->assertUnauthorized();
});

test('removed customer endpoints stay absent for machine and desktop credentials', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $desktop = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->json('data.token');
    $integration = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create()->createToken('licensekit:api', ['*'], now()->addDay())->plainTextToken;
    foreach ([$desktop, $integration] as $token) {
        $this->withToken($token)->getJson('/api/licensekit/v1/entitlements')->assertNotFound();
        $this->app['auth']->forgetGuards();
    }
});

test('user entitlement listing isolates owners and omits credentials', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $own = Entitlement::factory()->create(['user_id' => $user->id]);
    $foreign = Entitlement::factory()->create(['user_id' => $other->id]);
    $page = app(CustomerLicenses::class)->paginate($user);
    expect($page->total())->toBe(1);
    expect($page->items()[0]['id'])->toBe($own->id);
});

test('UUID and ULID host user keys work for claims and desktop access', function (string $model) {
    config(['auth.providers.users.model' => $model]);
    $this->app['db']->connection()->rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->app['db']->connection()->beginTransaction();
    $user = $model::create(['email' => 'key@example.com', 'password' => 'password', 'email_verified_at' => now()]);
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $entitlement = app(ClaimLicense::class)->claim($user, $key->key);
    expect($entitlement->user_id)->toBe($user->getKey());
    expect(app(ClientSessions::class)->login($plan->product, $user->email, 'password', desktopRequest($user)['hwid'])['token'])->toBeString();
})->with([[UuidUser::class], [UlidUser::class]]);

test('installation check is read only and reports missing Sanctum storage', function () {
    $this->artisan('licensekit:check')->assertSuccessful();
    Schema::drop('personal_access_tokens');
    $this->artisan('licensekit:check')->assertFailed();
    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});

test('user security cleanup works while optional module schema is unavailable', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid'])['token'];
    config(['licensekit.modules.downloads' => true]);

    $user->update(['password' => 'new-password']);

    expect(password_verify('new-password', $user->fresh()->password))->toBeTrue();
    expect(PersonalAccessToken::findToken($token))->toBeNull();
    $this->assertDatabaseCount('client_sessions', 0);
});

test('host users can update and delete before licensing storage is installed', function () {
    Schema::drop('client_sessions');
    Schema::drop('licensekit_audit_events');
    Schema::drop('licensekit_license_keys');
    Schema::drop('licensekit_entitlements');
    $user = User::factory()->create();

    $user->update(['password' => 'new-password']);
    expect(password_verify('new-password', $user->fresh()->password))->toBeTrue();
    $user->delete();
    expect($user->fresh())->toBeNull();
});

test('admin reset override requires a reason', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid']);
    expect(fn () => app(LicensedDevices::class)->adminReset($entitlement->user, $entitlement->product, $admin, ''))
        ->toThrow(LicensingException::class, 'reason required');
    app(LicensedDevices::class)->adminReset($entitlement->user, $entitlement->product, $admin, 'Support request');
    expect($entitlement->fresh()->device)->toBeNull();
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'device_reset', 'reason' => 'Support request']);
});

test('administrative revocation invalidates only affected desktop session and records reason', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid']);
    app(ManageEntitlements::class)->revoke($entitlement, testAdminActor(), 'Refund');
    expect($entitlement->fresh()->isRevoked())->toBeTrue();
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'license_status_changed', 'reason' => 'Refund']);
});
