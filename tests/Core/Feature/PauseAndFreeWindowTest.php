<?php

use Carbon\CarbonImmutable;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Tests\Core\Fixtures\User;

test('paused redemption banks batch time and lifetime redemption clears pause', function () {
    $user = User::factory()->create();
    $license = License::factory()->paused(3600)->create(['user_id' => $user->id]);
    $key = LicenseKey::factory()->create(['batch_id' => Batch::factory()->create(['plan_id' => $license->product->plans()->create(['name' => 'Day', 'duration_days' => 1])->id, 'duration_days' => 1])->id]);

    app(ClaimLicense::class)->claim($user, $key->key);

    expect($license->fresh()->remaining_seconds)->toBe(90000);
    $key = LicenseKey::factory()->create(['batch_id' => Batch::factory()->create(['plan_id' => $key->batch->plan_id, 'duration_days' => null])->id]);
    app(ClaimLicense::class)->claim($user, $key->key);
    expect($license->fresh()->status)->toBe('active')->and($license->fresh()->is_lifetime)->toBeTrue();
    expect($license->fresh()->paused_at)->toBeNull()->and($license->fresh()->remaining_seconds)->toBeNull();
});

test('unsuspend restores banked pause then applies elapsed resume date from now', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, 'expires_at' => now()->addHour()]);
    $tools = app(ManageLicenses::class);
    $tools->pause($license, $user, until: CarbonImmutable::now()->addDay());
    $tools->suspend($license, $user);
    $tools->unsuspend($license, $user);
    expect($license->fresh()->status)->toBe('paused');
    $tools->suspend($license, $user);
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));

    $tools->unsuspend($license, $user);

    expect($license->fresh()->status)->toBe('active');
    expect($license->fresh()->expires_at->toIso8601String())->toBe('2026-10-03T13:00:00+00:00');
});

test('free window includes start excludes end and preserves paused time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    $license = License::factory()->paused(3600)->create(['user_id' => User::factory()->create()->id]);
    $license->product->update(['free_from' => now(), 'free_until' => now()->addHour()]);

    expect(app(AccessPolicy::class)->authorize($license->user, $license->product)->source)->toBe('free');
    expect($license->fresh()->remaining_seconds)->toBe(3600);
    $license->update(['status' => 'revoked']);
    expect(fn () => app(AccessPolicy::class)->authorize($license->user, $license->product))->toThrow(LicensingException::class, 'cancelled');
    $license->update(['status' => 'paused']);
    $this->travelTo(CarbonImmutable::parse('2026-10-01T13:00:00Z'));
    expect(fn () => app(AccessPolicy::class)->authorize($license->user, $license->product))->toThrow(LicensingException::class, 'paused');
});
