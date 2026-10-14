<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Database\Factories\Core\AuditEventFactory;

/**
 * @property int $id
 * @property string $actor
 * @property string $event
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $reason
 * @property array<string, mixed> $before
 * @property array<string, mixed> $after
 */
#[Fillable(['actor', 'event', 'subject_type', 'subject_id', 'reason', 'before', 'after'])]
class AuditEvent extends Model
{
    protected $table = 'licensekit_audit_events';

    protected static function newFactory(): AuditEventFactory
    {
        return AuditEventFactory::new();
    }

    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['subject_id' => 'string', 'before' => 'array', 'after' => 'array'];
    }
}
