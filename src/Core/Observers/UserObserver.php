<?php

namespace LicenseKit\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\LoaderSession;

class UserObserver
{
    public function deleting(Model $user): void
    {
        LoaderSession::where('user_id', $user->getKey())->delete();
    }
}
