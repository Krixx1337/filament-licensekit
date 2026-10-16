<?php

namespace LicenseKit\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LicenseKit\Core\Models\Product;

/** @mixin Product */
class ManagementProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug,
            'status' => $this->status, 'requires_hwid' => $this->requires_hwid];
    }
}
