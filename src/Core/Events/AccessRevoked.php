<?php

namespace LicenseKit\Core\Events;

use LicenseKit\Core\Models\License;

final readonly class AccessRevoked
{
    public function __construct(public License $license) {}
}
