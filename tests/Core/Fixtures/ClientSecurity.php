<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class ClientSecurity
{
    public function fingerprint(Model $user): string
    {
        $values = array_intersect_key($user->getAttributes(), array_flip(array_filter([$user instanceof Authenticatable ? $user->getAuthPasswordName() : 'password', 'email', 'username', config('licensekit.user.suspended_column')], 'is_string')));

        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
