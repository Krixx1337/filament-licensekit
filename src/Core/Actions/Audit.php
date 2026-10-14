<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\AuditEvent;

class Audit
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after */
    public function record(string $actor, string $event, Model $subject, array $before = [], array $after = [], ?string $reason = null): void
    {
        AuditEvent::create([
            'actor' => $actor, 'event' => $event, 'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(), 'before' => $before, 'after' => $after, 'reason' => $reason,
        ]);
    }
}
