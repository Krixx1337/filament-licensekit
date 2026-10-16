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
            && (! ($user instanceof MustVerifyEmail) || $user->hasVerifiedEmail());
    }

    public function suspended(Model $user): bool
    {
        $column = config('licensekit.user_suspended_column');

        return is_string($column) && (bool) $user->getAttribute($column);
    }
}
