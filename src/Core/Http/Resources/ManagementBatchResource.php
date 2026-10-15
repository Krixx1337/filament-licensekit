<?php

namespace LicenseKit\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;

/** @mixin Batch */
class ManagementBatchResource extends JsonResource
{
    private bool $includeKeys = false;

    public function includingKeys(bool $includeKeys): self
    {
        $this->includeKeys = $includeKeys;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'issuer' => $this->issuer, 'reference' => $this->reference, 'plan_id' => $this->license_plan_id,
            'quantity' => $this->quantity, 'duration_days' => $this->duration_days, 'label' => $this->label,
            'total_count' => $this->whenHas('total_count'),
            'claimed_count' => $this->whenHas('claimed_count'),
            'unclaimed_count' => $this->whenHas('unclaimed_count'),
            'revoked_count' => $this->whenHas('revoked_count'),
            'keys' => $this->when($this->includeKeys, fn () => $this->keys->map(fn (LicenseKey $key): array => ['id' => $key->id, 'key' => $key->key])->all())];
    }
}
