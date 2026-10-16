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
        return ['id' => $this->id, 'source' => $this->source, 'reference' => $this->reference, 'plan_id' => $this->plan_id,
            'quantity' => $this->quantity, 'duration_days' => $this->duration_days, 'label' => $this->label,
            'total_count' => $this->whenHas('total_count'),
            'redeemed_count' => $this->whenHas('redeemed_count'),
            'unused_count' => $this->whenHas('unused_count'),
            'cancelled_count' => $this->whenHas('cancelled_count'),
            'keys' => $this->when($this->includeKeys, fn () => $this->keys->map(fn (LicenseKey $key): array => ['id' => $key->id, 'key' => $key->key])->all())];
    }
}
