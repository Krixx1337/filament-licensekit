<?php

use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;
use LicenseKit\Core\Actions\ManageApiClients;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\User;

test('integration rotation replaces only client tokens and records no secrets', function () {
    $client = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    $other = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    $old = $client->createToken('old')->plainTextToken;
    $unrelated = $other->createToken('other')->accessToken;
    $token = app(ManageApiClients::class)->rotate($client, CarbonImmutable::now()->addYear(), testAdminActor());
    expect(PersonalAccessToken::findToken($old))->toBeNull();
    expect($unrelated->fresh())->not->toBeNull();
    expect(PersonalAccessToken::findToken($token)->abilities)->toBe(['licensekit:api', 'licenses:create', 'keys:read']);
    $audit = AuditEvent::where('event', 'management_token_rotated')->first();
    expect(json_encode($audit->toArray()))->not->toContain($token);
    app(ManageApiClients::class)->revoke($client, testAdminActor());
    expect(PersonalAccessToken::findToken($token))->toBeNull();
});

test('invalid expiry preserves existing integration token', function () {
    $client = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    $old = $client->createToken('old')->plainTextToken;
    expect(fn () => app(ManageApiClients::class)->rotate($client, CarbonImmutable::now()->subMinute(), testAdminActor()))->toThrow(LicensingException::class);
    expect(PersonalAccessToken::findToken($old))->not->toBeNull();
});

test('session termination keeps device binding and requires reason', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $devices = app(ClientSessions::class);
    $token = $devices->login($product, $user->email, 'password', 'pc')['token'];
    expect(fn () => $devices->terminate($license, testAdminActor(), ''))->toThrow(LicensingException::class);
    $devices->terminate($license, testAdminActor(), 'Support');
    expect(PersonalAccessToken::findToken($token))->toBeNull();
    expect(lockedDeviceCount())->toBe(1);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'desktop_session_terminated', 'reason' => 'Support']);
});

test('disabled integration client cannot rotate an existing token', function () {
    $client = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create(['is_enabled' => false]);
    $old = $client->createToken('old')->plainTextToken;
    expect(fn () => app(ManageApiClients::class)->rotate($client, CarbonImmutable::now()->addYear(), testAdminActor()))->toThrow(LicensingException::class, 'management client disabled');
    expect(PersonalAccessToken::findToken($old))->not->toBeNull();
});
