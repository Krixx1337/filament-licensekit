<?php

namespace LicenseKit\Core\Users;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;

class UserAccess
{
    public function available(Model $user): bool
    {
        $model = ConfiguredUserModel::className();

        return $user instanceof $model && ! $this->suspended($user)
            && (! config('licensekit.user.require_verified') || ($user instanceof MustVerifyEmail && $user->hasVerifiedEmail()));
    }

    public function suspended(Model $user): bool
    {
        $column = config('licensekit.user.suspended_column');

        return is_string($column) && (bool) $user->getAttribute($column);
    }
}
