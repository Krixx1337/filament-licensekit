<?php

use LicenseKit\Core\Actions\ExactDeviceMatcher;

return [
    'local_integrations' => [],
    'management' => [
        'enabled' => false,
        'prefix' => 'api/licensekit/v1/manage',
        'domain' => null,
        'middleware' => [],
        'rate_limits' => ['reads' => 120, 'writes' => 60],
    ],
    'user' => ['guard' => null, 'display_column' => 'email', 'suspended_column' => null, 'require_verified' => true],
    'access_restrictions' => [],
    'maximum_batch_size' => 1000,
    'device_matcher' => ExactDeviceMatcher::class,
];
