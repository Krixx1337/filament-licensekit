<?php

namespace LicenseKit\Core\Events;

use LicenseKit\Core\Models\License;

final readonly class LicenseAccessInvalidated
{
    public function __construct(public License $license) {}
}
