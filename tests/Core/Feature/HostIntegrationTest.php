<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Events\KeyRedeemed;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Tests\Core\Fixtures\User;

test('claimed event contains linked provenance and waits for the host transaction', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $received = [];
    Event::listen(KeyRedeemed::class, function (KeyRedeemed $event) use (&$received): void {
        $received[] = $event;
    });

    DB::beginTransaction();
    $license = app(ClaimLicense::class)->claim($user, $key->key);
    expect($received)->toBe([]);
    DB::commit();

    expect($received)->toHaveCount(1);
    expect($received[0]->keyId)->toBe($key->id);
    expect($received[0]->licenseId)->toBe($license->id);
    expect($key->fresh()->license_id)->toBe($license->id);
});

test('host rollback discards claim notification and restores key state', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $received = [];
    Event::listen(KeyRedeemed::class, function ($event) use (&$received): void {
        $received[] = $event;
    });

    DB::beginTransaction();
    app(ClaimLicense::class)->claim($user, $key->key);
    DB::rollBack();

    expect($received)->toBe([]);
    expect($key->fresh()->redeemed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_licenses', 0);
});

test('initial schema can roll back and reinstall for fresh licensing', function () {
    LicenseKey::factory()->create();
    $migration = require __DIR__.'/../../../database/migrations/core/2026_10_12_000001_create_licensekit_core_tables.php';

    $migration->down();

    expect(Schema::hasTable('licensekit_keys'))->toBeFalse();
    $migration->up();
    $key = LicenseKey::factory()->create();
    $user = User::factory()->create();
    app(ClaimLicense::class)->claim($user, $key->key);
    $this->assertDatabaseCount('licensekit_licenses', 1);
});
