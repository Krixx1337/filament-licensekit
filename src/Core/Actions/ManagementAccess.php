<?php

namespace LicenseKit\Core\Actions;

use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;

class ManagementAccess
{
    public function authenticate(#[\SensitiveParameter] string $bearer): ManagementAuthorization
    {
        $token = Sanctum::personalAccessTokenModel()::findToken($bearer);
        if ($token === null || ! $token->tokenable instanceof ApiClient || $token->name !== 'licensekit:api'
            || $token->expires_at === null || $token->expires_at->lessThanOrEqualTo(now())
            || ! in_array('licensekit:api', $token->abilities, true)) {
            throw new LicensingException('invalid_token', 401);
        }
        $client = $token->tokenable;
        if (! $client->is_enabled) {
            throw new LicensingException('invalid_token', 401);
        }

        return new ManagementAuthorization($client, $token);
    }

    /**
     * @template T
     *
     * @param  Closure(ManagementAuthorization): T  $operation
     * @return T
     */
    public function run(#[\SensitiveParameter] string $bearer, ?string $permission, Closure $operation): mixed
    {
        return DB::transaction(function () use ($bearer, $permission, $operation) {
            $initial = $this->authenticate($bearer);
            $query = ApiClient::whereKey($initial->client->id);
            if ($permission === 'licenses:create') {
                $query->lockForUpdate();
            } else {
                $query->sharedLock();
            }
            $client = $query->firstOrFail();
            $token = Sanctum::personalAccessTokenModel()::whereKey($initial->token->id)->sharedLock()->first();
            if ($token === null || ! $client->is_enabled || $token->expires_at === null || $token->expires_at->lessThanOrEqualTo(now()) || $token->name !== 'licensekit:api' || ! in_array('licensekit:api', $token->abilities, true)) {
                throw new LicensingException('invalid_token', 401);
            }
            $context = new ManagementAuthorization($client, $token);
            if ($permission !== null) {
                $context->requirePermission($permission);
            }

            return $operation($context);
        }, 3);
    }
}
