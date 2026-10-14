<?php

namespace LicenseKit\Core\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class LicensingException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $error, public readonly int $status = 403)
    {
        parent::__construct(str_replace('_', ' ', $error));
    }

}
