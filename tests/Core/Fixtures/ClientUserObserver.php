<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

class ClientUserObserver
{
    public function updated(Model $user): void
    {
        $fields = array_filter([$user instanceof Authenticatable ? $user->getAuthPasswordName() : 'password', 'email', 'username', config('licensekit.user.suspended_column')]);
        if ($user->wasChanged($fields)) {
            Event::dispatch(new HostUserSecurityChanged($user));
        }
    }
}
