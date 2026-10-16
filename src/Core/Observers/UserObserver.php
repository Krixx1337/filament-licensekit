<?php

namespace LicenseKit\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Core\Models\LoaderSession;

class UserObserver
{
    public function deleting(Model $user): void
    {
        LoaderSession::where('user_id', $user->getKey())->delete();
        // The foreign key clears the owner afterwards, which would otherwise leave a working, unowned bot token.
        ApiConnection::where('user_id', $user->getKey())->update(['is_active' => false]);
    }
}
