<?php

use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Livewire\MyLicenses;
use LicenseKit\Tests\Core\Fixtures\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->routingSettings = ['licensekit.portal_api_enabled' => true];
    $this->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});

test('portal authentication ownership and redemption use shared services', function () {
    $user = User::factory()->create();
    $other = License::factory()->create(['user_id' => User::factory()->create()->id, 'device' => 'other-pc']);
    $key = LicenseKey::factory()->create();
    $this->getJson(route('licensekit.portal.licenses'))->assertUnauthorized();
    $this->actingAs($user)->postJson(route('licensekit.portal.reset', $other->id))->assertNotFound();
    $this->actingAs($user)->postJson(route('licensekit.portal.redeem'), ['key' => $key->key])->assertOk()->assertJsonPath('product_id', $key->batch->plan->product_id);
    $this->getJson(route('licensekit.portal.licenses'))->assertJsonCount(1, 'data');
    expect($other->fresh()->device)->toBe('other-pc');
});

test('Livewire component redeems resets and rejects tampered license IDs', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $this->actingAs($user);
    Livewire::test(MyLicenses::class)->set('key', $key->key)->call('redeem')->assertHasNoErrors()->assertSee('Key redeemed');
    $license = License::sole();
    $license->update(['device' => 'pc']);
    Livewire::test(MyLicenses::class)->call('resetDevice', $license->id)->assertHasNoErrors();
    expect($license->fresh()->device)->toBeNull();
    $other = License::factory()->create(['user_id' => User::factory()->create()->id]);
    Livewire::test(MyLicenses::class)->call('resetDevice', $other->id)->assertNotFound();
});

test('unverified customer sees verification guidance and cannot redeem', function () {
    $user = User::factory()->create(['email_verified_at' => null]);
    $key = LicenseKey::factory()->create();
    $this->actingAs($user);
    Livewire::test(MyLicenses::class)->assertSee('Verify your email')->set('key', $key->key)->call('redeem')->assertHasErrors('key');
    expect($key->fresh()->redeemed_at)->toBeNull();
});

test('portal writes require CSRF outside test bypass', function () {
    $this->actingAs(User::factory()->create());
    $this->app['env'] = 'production';
    $this->postJson(route('licensekit.portal.redeem'), ['key' => 'bad'])->assertStatus(419);
});
