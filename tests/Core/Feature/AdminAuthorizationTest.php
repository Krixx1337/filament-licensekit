<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\AdminAuthorization;
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
