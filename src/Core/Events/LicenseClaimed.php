<?php

namespace LicenseKit\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use LicenseKit\Core\Data\ClaimProvenance;

/** Informational event. Delivery is after commit, but is not durable. */
final readonly class LicenseClaimed implements ShouldDispatchAfterCommit
{
    public function __construct(public ClaimProvenance $claim) {}
}
