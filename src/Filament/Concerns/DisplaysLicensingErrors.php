<?php

namespace LicenseKit\Filament\Concerns;

use Closure;
use Filament\Notifications\Notification;
use LicenseKit\Core\Exceptions\LicensingException;
use Throwable;

trait DisplaysLicensingErrors
{
    public function exception(Throwable $e, Closure $stopPropagation): void
    {
        if ($e instanceof LicensingException) {
            Notification::make()->title($e->friendlyMessage())->danger()->send();
            $stopPropagation();
        }
    }
}
