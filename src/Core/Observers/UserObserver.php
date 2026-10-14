<?php

namespace LicenseKit\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Events\ProductAccessInvalidated;

class UserObserver
{
    public function deleting(Model $user): void
    {
        Event::dispatch(new ProductAccessInvalidated($user));
    }
}
