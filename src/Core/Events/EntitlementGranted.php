<?php

namespace LicenseKit\Core\Events;

use Carbon\CarbonImmutable;
use LicenseKit\Core\Models\Entitlement;

/** Synchronous extension point inside the claim transaction; exceptions roll back the grant. */
class EntitlementGranted
{
    public function __construct(public readonly Entitlement $entitlement, public readonly ?int $seconds, public readonly CarbonImmutable $claimedAt) {}
}
