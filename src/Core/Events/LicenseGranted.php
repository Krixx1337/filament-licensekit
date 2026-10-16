<?php

namespace LicenseKit\Core\Events;

use Carbon\CarbonImmutable;
use LicenseKit\Core\Data\ClaimProvenance;
use LicenseKit\Core\Models\License;

/** Synchronous extension point inside the claim transaction; exceptions roll back the grant. */
class LicenseGranted
{
    public function __construct(public readonly License $license, public readonly ?int $seconds, public readonly CarbonImmutable $claimedAt, public readonly ?ClaimProvenance $claim = null) {}
}
