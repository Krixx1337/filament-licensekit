<?php

use LicenseKit\Core\Actions\DefaultHwidHandler;

return [
    'local_integrations' => [],
    'features' => ['licensing_bans' => true],
    'modules' => ['outages' => false],
    'device_binding' => ['scope' => 'per_product', 'storage' => 'plain', 'customer_reset' => ['enabled' => true, 'cooldown_days' => 7, 'require_reason' => false]],
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
    'hwid_handler' => DefaultHwidHandler::class,
];
