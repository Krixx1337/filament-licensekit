<?php

namespace LicenseKit\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LicenseKit\Core\Models\LicensePlan;

/** @mixin LicensePlan */
class ManagementPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_id, 'name' => $this->name,
            'duration_days' => $this->duration_days, 'is_enabled' => $this->is_enabled];
    }
}
