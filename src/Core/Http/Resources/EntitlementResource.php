<?php

namespace LicenseKit\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LicenseKit\Core\Models\Entitlement;

/** @mixin Entitlement */
class EntitlementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_id, 'lifetime' => $this->is_lifetime,
            'expires_at' => $this->expires_at?->toIso8601String(), 'paused_at' => $this->paused_at?->toIso8601String(), 'remaining_seconds' => $this->remaining_seconds];
    }
}
