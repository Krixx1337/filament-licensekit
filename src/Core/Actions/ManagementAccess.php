<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Core\Users\UserAccess;

class ManagementAccess
{
    public function authenticate(#[\SensitiveParameter] string $bearer): ManagementAuthorization
    {
        if (strlen($bearer) !== 64) {
            throw new LicensingException('invalid_token', 401);
        }
        $connection = ApiConnection::where('token_hash', hash('sha256', $bearer))->where('is_active', true)->first();
        if ($connection === null) {
            throw new LicensingException('invalid_token', 401);
        }
        // Only suspension blocks a reseller's bot; email verification is a customer sign-in rule.
        if ($connection->user_id !== null && ($connection->user === null || app(UserAccess::class)->suspended($connection->user))) {
            throw new LicensingException('user_unavailable', 403);
        }
        $connection->forceFill(['last_used_at' => now()])->save();

        return new ManagementAuthorization($connection);
    }
}
