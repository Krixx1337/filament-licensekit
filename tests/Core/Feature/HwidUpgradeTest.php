<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Actions\DefaultHwidHandler;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Actions\Hwids;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\StructuredHwid;
use LicenseKit\Tests\Core\Fixtures\User;

beforeEach(function () {
    config(['licensekit.hwid_handler' => StructuredHwid::class]);
});

test('explicit upgrade preserves cooldown and snapshots and invalidates scoped sessions', function (string $scope, string $storage) {
    $this->freezeSecond();
    config(['licensekit.device_binding.scope' => $scope, 'licensekit.device_binding.storage' => $storage]);
    $user = User::factory()->create();
    $products = Product::factory()->count(2)->create();
    foreach ($products as $product) {
        Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        app(ClientSessions::class)->login($product, $user->email, 'password', ['v' => 1, 'id' => 'pc']);
    }
    app(DeviceBindings::class)->reset($user, $products[0], $user);
    app(ClientSessions::class)->login($products[0], $user->email, 'password', ['v' => 1, 'id' => 'pc']);
    $binding = app(DeviceBindings::class)->find($user, $products[0]);
    $history = DeviceResetHistory::firstOrFail()->getAttributes();
    $boundAt = $binding->bound_at;
    $resetAt = $binding->last_hwid_reset_at;
    $this->travel(1)->minute();

    $context = app(LicensedDevices::class)->upgrade($user, $products[0], ['v' => 2, 'id' => 'PC', 'tpm' => 'new-signal']);

    expect($context->hwid)->toBe(['id' => 'pc', 'tpm' => 'new-signal', 'v' => 2]);
    $binding->refresh();
    expect($binding->hwid)->toBe($context->hwid)->and($binding->bound_at->equalTo($boundAt))->toBeTrue()
        ->and($binding->last_hwid_reset_at->equalTo($resetAt))->toBeTrue();
    expect(DeviceResetHistory::firstOrFail()->getAttributes())->toBe($history);
    $this->assertDatabaseCount('client_sessions', $scope === 'global' ? 0 : 1);
    $this->assertDatabaseCount('personal_access_tokens', $scope === 'global' ? 0 : 1);
    $audit = AuditEvent::where('event', 'hwid_upgraded')->sole();
    expect($audit->after)->toBe(['scope_key' => $binding->scope_key])->and($audit->toJson())->not->toContain('new-signal');
    app(LicensedDevices::class)->upgrade($user, $products[0], $context->hwid);
    expect(AuditEvent::where('event', 'hwid_upgraded')->count())->toBe(1);
})->with(['per_product', 'global'])->with(['plain', 'encrypted']);

test('ordinary fuzzy checks never replace original baseline and exact policy varies per product', function () {
    $user = User::factory()->create();
    $fuzzy = Product::factory()->create(['slug' => 'fuzzy']);
    $strict = Product::factory()->create(['slug' => 'strict']);
    $baseline = ['v' => 1, 'id' => 'pc', 'disk' => 'original'];
    app(DeviceBindings::class)->bind($user, $fuzzy, $baseline);
    app(DeviceBindings::class)->bind($user, $strict, $baseline);
    foreach (['disk-a', 'disk-b'] as $disk) {
        app(DeviceBindings::class)->bind($user, $fuzzy, ['disk' => $disk, 'id' => 'PC', 'v' => 2]);
        app(DeviceBindings::class)->verify($user, $fuzzy, ['v' => 2, 'id' => 'pc', 'disk' => $disk]);
    }

    expect(app(DeviceBindings::class)->find($user, $fuzzy)->hwid)->toBe(['disk' => 'original', 'id' => 'pc', 'v' => 1]);
    expect(fn () => app(DeviceBindings::class)->verify($user, $strict, ['v' => 1, 'id' => 'pc', 'disk' => 'new']))->toThrow(LicensingException::class, 'hwid mismatch');
    app(DeviceBindings::class)->verify($user, $strict, ['disk' => 'original', 'v' => 1, 'id' => 'PC']);
    expect(fn () => app(DeviceBindings::class)->verify($user, $fuzzy, ['v' => 2, 'id' => 'another']))->toThrow(LicensingException::class, 'hwid mismatch');
});

test('denied upgrade preserves binding and credentials', function (string $failure, string $error) {
    $this->freezeSecond();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(ClientSessions::class)->login($product, $user->email, 'password', ['v' => 2, 'id' => 'pc']);
    $before = DeviceBinding::firstOrFail()->getAttributes();
    $payload = ['v' => 3, 'id' => 'pc'];
    match ($failure) {
        'downgrade' => $payload['v'] = 1,
        'mismatch' => $payload['id'] = 'another',
        'missing-evidence' => $payload['id'] = '',
        'blocked' => config(['testing.device_denied' => true]),
        'expired' => $entitlement->update(['expires_at' => now()->subSecond()]),
    };

    expect(fn () => app(LicensedDevices::class)->upgrade($user, $product, $payload))->toThrow(LicensingException::class, $error);
    expect(DeviceBinding::firstOrFail()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('client_sessions', 1);
    expect(AuditEvent::where('event', 'hwid_upgraded')->count())->toBe(0);
})->with([
    ['downgrade', 'hwid upgrade denied'], ['mismatch', 'hwid mismatch'], ['missing-evidence', 'invalid hwid'],
    ['blocked', 'device banned'], ['expired', 'license expired'],
]);

test('upgrade cannot create or rebind a missing device', function (bool $reset) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    if ($reset) {
        app(DeviceBindings::class)->bind($user, $product, ['v' => 1, 'id' => 'pc']);
        app(DeviceBindings::class)->reset($user, $product, $user);
    }

    expect(fn () => app(LicensedDevices::class)->upgrade($user, $product, ['v' => 2, 'id' => 'pc']))->toThrow(LicensingException::class, 'device not bound');
    $this->assertDatabaseCount('licensekit_device_bindings', $reset ? 1 : 0);
    $this->assertDatabaseCount('licensekit_device_reset_history', $reset ? 1 : 0);
})->with([false, true]);

test('upgrade listener or enclosing credential failure rolls back binding audit and cleanup', function (string $failure) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(ClientSessions::class)->login($product, $user->email, 'password', ['v' => 1, 'id' => 'pc']);
    $before = DeviceBinding::firstOrFail()->getAttributes();
    if ($failure === 'listener') {
        Event::listen(ProductAccessInvalidated::class, function (): void {
            throw new RuntimeException('Upgrade failed');
        });
    }

    expect(fn () => DB::transaction(function () use ($user, $product, $failure): void {
        app(LicensedDevices::class)->upgrade($user, $product, ['v' => 2, 'id' => 'pc']);
        if ($failure === 'credential') {
            throw new RuntimeException('Upgrade failed');
        }
    }))->toThrow(RuntimeException::class, 'Upgrade failed');
    expect(DeviceBinding::firstOrFail()->getAttributes())->toBe($before);
    expect(AuditEvent::where('event', 'hwid_upgraded')->count())->toBe(0);
    $this->assertDatabaseCount('client_sessions', 1);
    $this->assertDatabaseCount('personal_access_tokens', 1);
})->with(['listener', 'credential']);

test('host free access permits explicit upgrade and default handler refuses replacement', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    app(DeviceBindings::class)->bind($user, $product, ['v' => 1, 'id' => 'pc']);
    $context = app(LicensedDevices::class)->upgrade($user, $product, ['v' => 2, 'id' => 'pc'], freeUntil: now()->toImmutable()->addHour());
    expect($context->access->source)->toBe('host_free');
    config(['licensekit.hwid_handler' => DefaultHwidHandler::class]);
    expect(app(Hwids::class)->handler->canUpgrade($product, ['value' => 'pc'], ['value' => 'new']))->toBeFalse();
});

test('generic payload bounds reject invalid input before host normalization', function (array $payload) {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(DeviceBindings::class)->bind($user, $product, $payload))->toThrow(LicensingException::class, 'invalid hwid');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
})->with([[[]], [[1, 2]], [['v' => 1, 'id' => 'pc', 'extra' => str_repeat('x', 8192)]], [['v' => 1, 'id' => "\xB1"]], [['v' => 1, 'id' => 'pc', 'extra' => new stdClass]]]);

class InvalidNormalizedHwid extends StructuredHwid
{
    public function normalize(Product $product, array $payload): array
    {
        return config('testing.normalized_list') ? ['pc'] : ['id' => str_repeat('x', 8192)];
    }
}

test('invalid handler output fails closed before writing bindings', function (bool $list) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    config(['licensekit.hwid_handler' => InvalidNormalizedHwid::class, 'testing.normalized_list' => $list]);

    expect(fn () => app(DeviceBindings::class)->bind($user, $product, ['v' => 1, 'id' => 'pc']))->toThrow(LicensingException::class, 'licensing unavailable');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
})->with([false, true]);

test('invalid handler configuration denies licensing and reports setup problem', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    config(['licensekit.hwid_handler' => stdClass::class]);

    expect(fn () => app(LicensedDevices::class)->bind($user, $product, ['value' => 'pc']))->toThrow(LicensingException::class, 'licensing unavailable');
    $this->artisan('licensekit:check')->expectsOutputToContain('licensekit.hwid_handler must implement HwidHandler.')->assertFailed();
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
});

test('device verification performs no table-wide scope consistency query', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    app(DeviceBindings::class)->bind($user, $product, ['v' => 1, 'id' => 'pc']);
    DB::enableQueryLog();

    app(DeviceBindings::class)->verify($user, $product, ['v' => 2, 'id' => 'pc']);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $bindingQueries = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'licensekit_device_bindings'));
    expect($bindingQueries)->toHaveCount(1);
    expect(array_values($bindingQueries)[0]['bindings'])->toContain($user->id, 'product:'.$product->id);
});
