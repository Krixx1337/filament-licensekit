<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Product;
use LicenseKit\Support\ModuleReadiness;
use LicenseKit\Tests\Core\Fixtures\ClientSession;
use LicenseKit\Tests\Core\Fixtures\UlidUser;
use LicenseKit\Tests\Core\Fixtures\User;
use LicenseKit\Tests\Core\Fixtures\UuidUser;

test('successful resets append scoped history using the cooldown timestamp without exposing hardware data', function (string $scope) {
    $this->freezeTime();
    config(['licensekit.device_binding.scope' => $scope]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => app(DeviceBindings::class)->scopeKey($product)]);
    app(DeviceBindings::class)->reset($user, $product, $user);
    $history = DeviceResetHistory::firstOrFail();
    expect($history->user_id)->toBe((string) $user->id)->and($history->actor_id)->toBe((string) $user->id)
        ->and($history->scope)->toBe($scope)->and($history->product_id)->toBe($scope === 'global' ? null : $product->id)
        ->and($history->performed_by)->toBe('customer')->and($history->reason)->toBeNull()
        ->and($history->reset_at->equalTo($binding->fresh()->last_hwid_reset_at))->toBeTrue();
    expect(array_keys($history->toArray()))->toEqualCanonicalizing(['id', 'user_id', 'scope', 'product_id', 'reset_at', 'performed_by', 'actor_id', 'reason']);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'hwid_reset', 'actor' => 'user:'.$user->id]);
    $binding->update(['hwid' => ['value' => 'new'], 'bound_at' => now()]);
    $this->travel(7)->days();
    app(DeviceBindings::class)->reset($user, $product, $user);
    expect(DeviceResetHistory::count())->toBe(2)->and($history->fresh()->reset_at->equalTo($history->reset_at))->toBeTrue();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
})->with(['per_product', 'global']);

test('admin override is distinguished from an admin using customer self service', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->id === $admin->id);
    $product = Product::factory()->create();
    DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id, 'last_hwid_reset_at' => now()]);
    app(DeviceBindings::class)->adminReset($user, $product, $admin, 'PC replaced');
    $history = DeviceResetHistory::firstOrFail();
    expect($history->performed_by)->toBe('admin')->and($history->actor_id)->toBe((string) $admin->id)->and($history->reason)->toBe('PC replaced');
    DeviceBinding::factory()->create(['user_id' => $admin->id, 'scope_key' => 'product:'.$product->id]);
    app(DeviceBindings::class)->reset($admin, $product, $admin);
    expect(DeviceResetHistory::latest('id')->first()->performed_by)->toBe('customer');
});

test('denied resets create no history or audit and leave binding unchanged', function (string $failure) {
    $user = User::factory()->create();
    $actor = $failure === 'reset_forbidden' ? User::factory()->create() : $user;
    $product = Product::factory()->create();
    $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id,
        'last_hwid_reset_at' => $failure === 'reset_cooldown' ? now() : null,
        ...($failure === 'device_not_bound' ? ['hwid' => null] : [])]);
    if ($failure === 'reason_required') {
        Gate::define('licensekit.manage', fn () => true);
    }
    $original = $binding->fresh()->getAttributes();
    expect(fn () => $failure === 'reason_required' ? app(DeviceBindings::class)->adminReset($user, $product, $actor, ' ') : app(DeviceBindings::class)->reset($user, $product, $actor))
        ->toThrow(LicensingException::class, str_replace('_', ' ', $failure));
    expect($binding->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('licensekit_device_reset_history', 0);
    expect(AuditEvent::where('event', 'hwid_reset')->count())->toBe(0);
})->with(['reset_forbidden', 'reset_cooldown', 'device_not_bound', 'reason_required']);

test('history audit and invalidation failures roll back reset and participating cleanup', function (string $failure) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);
    $session = ClientSession::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $session->createToken('cleanup-test');
    $session->update(['personal_access_token_id' => $token->accessToken->id]);
    Event::listen(ProductAccessInvalidated::class, fn () => $token->accessToken->delete());
    $event = match ($failure) {
        'history' => 'eloquent.creating: '.DeviceResetHistory::class,
        'audit' => 'eloquent.creating: '.AuditEvent::class,
        default => ProductAccessInvalidated::class,
    };
    Event::listen($event, fn () => throw new RuntimeException('write failed'));
    expect(fn () => app(DeviceBindings::class)->reset($user, $product, $user))->toThrow(RuntimeException::class, 'write failed');
    expect($binding->fresh()->hwid)->not->toBeNull()->and($binding->fresh()->last_hwid_reset_at)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 1);
    $this->assertDatabaseCount('client_sessions', 1);
    $this->assertDatabaseCount('licensekit_device_reset_history', 0);
    expect(AuditEvent::where('event', 'hwid_reset')->count())->toBe(0);
})->with(['history', 'audit', 'listener']);

test('history survives permitted deletion of its owner actor and product', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $product = Product::factory()->create();
    Gate::define('licensekit.manage', fn () => true);
    DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);
    app(DeviceBindings::class)->adminReset($user, $product, $admin, 'Support');
    $history = DeviceResetHistory::firstOrFail();
    $user->delete();
    $admin->delete();
    $product->delete();
    expect($history->fresh()->user_id)->toBe((string) $user->id)->and($history->fresh()->actor_id)->toBe((string) $admin->id)
        ->and($history->fresh()->product_id)->toBe($product->id)->and($history->fresh()->user)->toBeNull()
        ->and($history->fresh()->actor)->toBeNull()->and($history->fresh()->product)->toBeNull();
});

test('reset history cannot be updated or deleted through model instances', function (string $operation) {
    $history = DeviceResetHistory::factory()->create();
    expect(fn () => $operation === 'update' ? $history->update(['reason' => 'changed']) : $history->delete())
        ->toThrow(LicensingException::class, 'reset history is immutable');
    expect($history->fresh()->reason)->toBeNull();
})->with(['update', 'delete']);

test('reset history uses configured UUID and ULID host identities', function (string $model) {
    config(['auth.providers.users.model' => $model]);
    DB::connection()->rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    DB::connection()->beginTransaction();
    $user = $model::create(User::factory()->make()->getAttributes());
    $admin = $model::create(User::factory()->make()->getAttributes());
    Gate::define('licensekit.manage', fn () => true);
    $product = Product::factory()->create();
    DeviceBinding::factory()->create(['user_id' => $user->getKey(), 'scope_key' => 'product:'.$product->id]);
    app(DeviceBindings::class)->adminReset($user, $product, $admin, 'Support');
    $history = DeviceResetHistory::firstOrFail();
    expect($history->user_id)->toBe($user->getKey())->and($history->actor_id)->toBe($admin->getKey())
        ->and($history->user->getKey())->toBe($user->getKey())->and($history->actor->getKey())->toBe($admin->getKey());
})->with([[UuidUser::class], [UlidUser::class]]);

test('missing reset history schema fails diagnostics without blocking unrelated authorization', function () {
    Schema::drop('licensekit_device_reset_history');
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing table: licensekit_device_reset_history')->assertFailed();
    expect(app(ModuleReadiness::class)->issues())->toContain('Missing core table: licensekit_device_reset_history. Run php artisan migrate with the installed LicenseKit version.');
    app(ModuleReadiness::class)->assertReady();
});

test('history stays UTC without shifting existing cooldown behavior in another host timezone', function () {
    $previousTimezone = date_default_timezone_get();
    try {
        config(['app.timezone' => 'Europe/Warsaw']);
        date_default_timezone_set('Europe/Warsaw');
        $this->freezeTime();
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);
        app(DeviceBindings::class)->reset($user, $product, $user);
        $history = DeviceResetHistory::firstOrFail();
        expect($history->getRawOriginal('reset_at'))->toBe(now('UTC')->format('Y-m-d H:i:s'))
            ->and($history->reset_at->equalTo($binding->fresh()->last_hwid_reset_at))->toBeTrue();
        expect(app(DeviceBindings::class)->eligibility($binding->fresh()))
            ->toBe(['allowed' => false, 'available_at' => now()->addDays(7)->toIso8601String(), 'reason' => 'reset_cooldown']);
    } finally {
        date_default_timezone_set($previousTimezone);
    }
});
