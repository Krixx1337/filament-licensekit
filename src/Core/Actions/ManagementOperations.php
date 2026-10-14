<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;

class ManagementOperations
{
    public function __construct(private ManagementAccess $access, private CreateBatch $batches) {}

    public function create(#[\SensitiveParameter] string $bearer, int $planId, int $quantity, string $reference, ?string $label = null): Batch
    {
        return $this->access->run($bearer, 'licenses:create', function (ManagementAuthorization $context) use ($planId, $quantity, $reference, $label): Batch {
            $plan = $this->plan($context, $planId, $quantity);

            return $this->batches->create($plan, $quantity, $reference, $context->issuer(), label: $label);
        });
    }

    private function plan(ManagementAuthorization $context, int $planId, int $quantity): LicensePlan
    {
        $plan = LicensePlan::findOrFail($planId);
        abort_unless($context->includesProduct($plan->product_id), 404);
        if ($quantity < 1 || $quantity > config('licensekit.maximum_batch_size')) {
            throw new LicensingException('invalid_quantity_or_duration', 422);
        }
        if ($quantity > $context->client->maximum_quantity) {
            throw new LicensingException('management_forbidden');
        }

        return $plan;
    }
}
