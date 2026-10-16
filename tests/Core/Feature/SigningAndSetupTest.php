<?php

use Illuminate\Support\Facades\Artisan;
use LicenseKit\Core\Actions\ResponseSigning;
use LicenseKit\Core\Exceptions\LicensingException;

test('signing installer preserves key and reports corruption without replacing it', function () {
    $signing = app(ResponseSigning::class);
    $public = $signing->publicKey();
    $signing->install();
    expect($signing->publicKey())->toBe($public);
    config(['licensekit.signing_key_path' => storage_path('framework/broken-signing.key')]);
    file_put_contents(config('licensekit.signing_key_path'), 'broken');
    try {
        expect(fn () => $signing->install())->toThrow(LicensingException::class);
        expect(file_get_contents(config('licensekit.signing_key_path')))->toBe('broken');
    } finally {
        unlink(config('licensekit.signing_key_path'));
    }
});

test('OpenAPI reflects registered routes and request validation', function () {
    Artisan::call('licensekit:openapi');
    $spec = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $login = $spec['paths']['/api/licensekit/{product}/login']['post'];
    expect($login['requestBody']['content']['application/json']['schema']['required'])->toBe(['login', 'password', 'device', 'nonce']);
    expect($spec['paths'])->toHaveCount(4);
});
