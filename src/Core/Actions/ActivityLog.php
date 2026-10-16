<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Users\ConfiguredUserModel;

class ActivityLog
{
    /** @param array<string, mixed> $meta */
    public function record(string $actor, string $event, Model $subject, array $meta = []): void
    {
        if ($subject instanceof License) {
            $meta['product_id'] = $subject->product_id;
            $meta['license_id'] = $subject->id;
        }
        if (str_starts_with($actor, 'user:')) {
            $meta['actor_label'] = ConfiguredUserModel::query()->whereKey(substr($actor, 5))->first()?->getAttribute(config('licensekit.user_label')) ?? $actor;
        }
        ActivityEntry::create(['actor' => $actor, 'event' => $event, 'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(), 'meta' => $meta]);
    }
}
