<?php

namespace LicenseKit\Core\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class LicensingException extends RuntimeException implements ShouldntReport
{
    public readonly int $status;

    /** @param array<string, mixed> $details */
    public function __construct(public readonly string $error, ?int $status = null, public readonly array $details = [])
    {
        $this->status = $status ?? ErrorCode::from($error)->status();
        parent::__construct(str_replace('_', ' ', $error));
    }

    public function friendlyMessage(): string
    {
        $message = __('licensekit::messages.'.$this->error);

        return $message === 'licensekit::messages.'.$this->error ? ucfirst($this->getMessage()).'.' : $message;
    }
}
