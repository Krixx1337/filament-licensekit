<?php

use LicenseKit\Core\Actions\ExactDeviceMatcher;

return [
    'client_enabled' => true,
    'client_prefix' => 'api/licensekit/v1',
    'client_login_column' => 'email',
    'store_api_enabled' => false,
    'store_api_prefix' => 'api/licensekit/store',
    'portal_api_enabled' => false,
    'portal_api_prefix' => 'account',
    'device_matcher' => ExactDeviceMatcher::class,
    'max_batch_size' => 1000,
    'user_label' => 'email',
    'user_guard' => null,
    'user_suspended_column' => null,
    'signing_key_path' => storage_path('app/private/licensekit-signing.key'),
];
