<?php

namespace LicenseKit\Core\Events;

use LicenseKit\Core\Models\Entitlement;

final readonly class EntitlementAccessInvalidated
{
    public function __construct(public Entitlement $entitlement) {}
}
