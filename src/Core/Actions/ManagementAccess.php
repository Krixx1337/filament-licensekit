<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiConnection;

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
        $connection->forceFill(['last_used_at' => now()])->save();

        return new ManagementAuthorization($connection);
    }
}
