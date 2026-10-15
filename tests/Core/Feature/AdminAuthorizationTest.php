<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\AdminAuthorization;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\AdminPolicy;
use LicenseKit\Tests\Core\Fixtures\User;

class MissingProductAdminPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }
}

class HwidImmunityPolicy
{
    public function bypassHwid(User $actor, Product $product): bool
    {
        return $actor->id === config('testing.immune_user') && $product->id === config('testing.immune_product');
    }
}

test('HWID immunity requires explicit product grant despite management access', function (string $authorization) {
    $actor = testAdminActor();
    $product = Product::factory()->create();
    app(DeviceBindings::class)->bind($actor, $product, ['value' => 'old']);
    if ($authorization === 'missing-policy-method') {
        Gate::policy(Product::class, MissingProductAdminPolicy::class);
    } elseif ($authorization === 'denied-gate') {
        Gate::define('bypassHwid', fn (): bool => false);
    }

    expect(fn () => app(DeviceBindings::class)->verify($actor, $product, ['value' => 'new']))->toThrow(LicensingException::class, 'hwid mismatch');

    expect(DeviceBinding::firstOrFail()->hwid)->toBe(['value' => 'old']);
})->with(['manager-only', 'missing-policy-method', 'denied-gate']);

test('explicit policy immunity is scoped to user and product', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $product = Product::factory()->create();
    $protected = Product::factory()->create();
    Gate::policy(Product::class, HwidImmunityPolicy::class);
    config(['testing.immune_user' => $actor->id, 'testing.immune_product' => $product->id]);

    app(DeviceBindings::class)->verify($actor, $product, ['value' => 'pc']);

    expect(fn () => app(DeviceBindings::class)->verify($other, $product, ['value' => 'pc']))->toThrow(LicensingException::class, 'device not bound');
    expect(fn () => app(DeviceBindings::class)->verify($actor, $protected, ['value' => 'pc']))->toThrow(LicensingException::class, 'device not bound');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
});

test('explicit authorization respects host hooks but denies unavailable actors', function () {
    $actor = User::factory()->create();
    $product = Product::factory()->create();
    Gate::before(fn (User $user): ?bool => $user->is($actor) ? true : null);
    $authorization = app(AdminAuthorization::class);

    expect($authorization->allowsExplicit($actor, 'bypassHwid', $product))->toBeTrue();
    $actor->update(['is_suspended' => true]);
    expect($authorization->allowsExplicit($actor, 'bypassHwid', $product))->toBeFalse();
    $actor->update(['is_suspended' => false, 'email_verified_at' => null]);
    expect($authorization->allowsExplicit($actor, 'bypassHwid', $product))->toBeFalse();
});

test('admin authorization falls back only for models without policies', function () {
    $actor = User::factory()->create();
    $access = app(AdminAuthorization::class);
    expect($access->allows($actor, 'viewAny', Product::class))->toBeFalse();
    Gate::define('licensekit.manage', fn () => true);
    expect($access->allows($actor, 'viewAny', Product::class))->toBeTrue();
    Gate::policy(Product::class, AdminPolicy::class);
    expect($access->allows($actor, 'viewAny', Product::class))->toBeFalse()
        ->and($access->allows($actor, 'viewAny', LicensePlan::class))->toBeTrue();
    config(['testing.admin_abilities' => ['viewAny']]);
    Gate::define('licensekit.manage', fn () => false);
    expect($access->allows($actor, 'viewAny', Product::class))->toBeTrue()
        ->and($access->allows($actor, 'viewAny', LicensePlan::class))->toBeFalse();
});

test('missing policy methods deny despite fallback gate and host super admin hook still works', function () {
    $actor = testAdminActor();
    Gate::policy(Product::class, MissingProductAdminPolicy::class);
    $product = Product::factory()->create();
    expect(app(AdminAuthorization::class)->allows($actor, 'addTime', $product))->toBeFalse();
    Gate::before(fn ($user) => $user->is($actor) ? true : null);
    expect(app(AdminAuthorization::class)->allows($actor, 'addTime', $product))->toBeTrue();
    $actor->update(['is_suspended' => true]);
    expect(app(AdminAuthorization::class)->allows($actor, 'addTime', $product))->toBeFalse();
    $actor->update(['is_suspended' => false, 'email_verified_at' => null]);
    expect(app(AdminAuthorization::class)->allows($actor, 'addTime', $product))->toBeFalse();
});
