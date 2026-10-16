<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\SerialKey;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

test('plans reject invalid durations before issuance', function (mixed $days) {
    expect(fn () => LicensePlan::factory()->create(['duration_days' => $days]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('licensekit_keys', 0);
})->with([0, -1, 0.5, 1.5, '', 3651]);

test('issuance replays original keys and duration even after plan changes', function () {
    $plan = LicensePlan::factory()->create();
    $keys = app(Keys::class);
    $context = new IssueContext(reference: 'order');
    $batch = $keys->issue($plan, 251, $context);
    $plan->update(['duration_days' => 1, 'is_active' => false]);

    $replay = $keys->issue($plan, 251, $context);

    expect($replay->id)->toBe($batch->id)->and($replay->duration_days)->toBe(30);
    expect($replay->keys->pluck('key')->all())->toBe($batch->keys->pluck('key')->all());
    expect($batch->keys)->toHaveCount(251);
    expect($batch->keys->pluck('key')->unique())->toHaveCount(251);
    foreach ($batch->keys as $key) {
        expect($key->key)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{5}(?:-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{5}){4}$/');
        expect($key->toArray())->not->toHaveKey('key');
    }
    expect(ActivityEntry::all()->toJson())->not->toContain($batch->keys[0]->key);
    expect(Schema::hasColumn('licensekit_keys', 'duration_days'))->toBeFalse();
    expect(Schema::hasColumn('licensekit_keys', 'plan_id'))->toBeFalse();
});

test('conflicting issuance retries and inactive plans create no extra keys', function () {
    $plan = LicensePlan::factory()->create();
    $keys = app(Keys::class);
    $keys->issue($plan, 1, new IssueContext(reference: 'order'));
    expect(fn () => $keys->issue($plan, 2, new IssueContext(reference: 'order')))->toThrow(LicensingException::class, 'batch conflict');
    $plan->update(['is_active' => false]);
    expect(fn () => $keys->issue($plan, 1, new IssueContext(reference: 'new')))->toThrow(LicensingException::class, 'plan unavailable');
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_keys', 1);
});

test('issuance validates quantity reference and label', function (array $data) {
    $plan = LicensePlan::factory()->create();
    expect(fn () => app(Keys::class)->issue($plan, $data['quantity'] ?? 1, new IssueContext(reference: $data['reference'] ?? 'order', label: $data['label'] ?? null)))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('licensekit_batches', 0);
})->with([[['quantity' => 0]], [['quantity' => 1001]], [['reference' => '']], [['label' => str_repeat('x', 256)]]]);

test('redemption normalizes key and rejects a second use', function () {
    $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
    $user = User::factory()->create();
    $batch = app(Keys::class)->issue(LicensePlan::factory()->create(), 1, new IssueContext);
    $key = $batch->keys[0];

    $license = app(ClaimLicense::class)->claim($user, ' '.strtolower(str_replace('-', '', $key->key)).' ');

    expect($license->expires_at->toIso8601String())->toBe('2026-01-31T00:00:00+00:00');
    expect($key->fresh()->license_id)->toBe($license->id);
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'key already used');
    $this->assertDatabaseCount('licensekit_licenses', 1);
});

test('renewal stacks on future expiry and restarts expired time from now', function (bool $expired, string $date) {
    $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, 'product_id' => $plan->product_id, 'expires_at' => $expired ? now()->subDay() : now()->addDays(10)]);
    $batch = app(Keys::class)->issue($plan, 1, new IssueContext);

    app(ClaimLicense::class)->claim($user, $batch->keys[0]->key);

    expect($license->fresh()->expires_at->toDateString())->toBe($date);
    $this->assertDatabaseCount('licensekit_licenses', 1);
})->with([[false, '2026-02-10'], [true, '2026-01-31']]);

test('lifetime upgrade uses batch snapshot and never consumes another key', function () {
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->lifetime()->create();
    $batch = app(Keys::class)->issue($plan, 2, new IssueContext);
    $plan->update(['duration_days' => 1]);

    $license = app(ClaimLicense::class)->claim($user, $batch->keys[0]->key);

    expect($license->is_lifetime)->toBeTrue()->and($license->expires_at)->toBeNull();
    expect(fn () => app(ClaimLicense::class)->claim($user, $batch->keys[1]->key))->toThrow(LicensingException::class, 'already lifetime');
    expect($batch->keys[1]->fresh()->redeemed_at)->toBeNull();
});

test('audit failures roll back both issuance and redemption', function (string $operation) {
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = LicenseKey::factory()->create();
    Event::listen('eloquent.creating: '.ActivityEntry::class, fn () => throw new RuntimeException('audit failed'));

    expect(fn () => $operation === 'issue' ? app(Keys::class)->issue($plan, 1, new IssueContext) : app(ClaimLicense::class)->claim($user, $key->key))
        ->toThrow(RuntimeException::class, 'audit failed');

    expect($key->fresh()->redeemed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_licenses', 0);
    $this->assertDatabaseCount('licensekit_keys', 1);
})->with(['issue', 'redeem']);

test('serial generation collisions roll back batch and keys', function () {
    $plan = LicensePlan::factory()->create();
    $this->mock(SerialKey::class)->shouldReceive('generate')->times(20)->andReturn('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE');
    expect(fn () => app(Keys::class)->issue($plan, 2, new IssueContext))->toThrow(LicensingException::class, 'key generation failed');
    $this->assertDatabaseCount('licensekit_batches', 0);
    $this->assertDatabaseCount('licensekit_keys', 0);
});

test('key requires its batch and issued plan cannot change product', function () {
    $key = LicenseKey::factory()->create();
    expect(fn () => $key->update(['batch_id' => null]))->toThrow(QueryException::class);
    $key->refresh();
    expect(fn () => $key->batch->delete())->toThrow(QueryException::class);
    expect(fn () => $key->batch->plan->update(['product_id' => Product::factory()->create()->id]))
        ->toThrow(LicensingException::class, 'plan product is immutable');
});
