<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Database\Factories\Core\ActivityEntryFactory;

/**
 * @property int $id
 * @property string $actor
 * @property string $event
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, mixed> $meta
 */
class ActivityEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor', 'event', 'subject_type', 'subject_id', 'meta'];

    protected $table = 'licensekit_activity_log';

    protected static function newFactory(): ActivityEntryFactory
    {
        return ActivityEntryFactory::new();
    }

    /** @use HasFactory<ActivityEntryFactory> */
    use HasFactory;

    public function description(): string
    {
        return $this->summary().' on '.class_basename($this->subject_type).' #'.$this->subject_id;
    }

    /** Who did what, without the subject: "admin@example.com added time". */
    public function summary(): string
    {
        $actor = $this->meta['actor_label'] ?? $this->actor;
        $verb = match ($this->event) {
            'key_redeemed' => 'redeemed a key', 'device_moved' => 'moved the device', 'device_reset' => 'reset the device',
            'device_banned' => 'banned the device', 'device_unbanned' => 'unbanned the device',
            'license_extended', 'product_time_added' => 'added time', 'license_paused' => 'paused the license',
            'license_resumed' => 'resumed the license', 'license_status_changed' => 'changed the license status',
            'keys_generated' => 'generated keys', 'product_created' => 'created a product', 'product_saved' => 'updated a product',
            default => str_replace('_', ' ', $this->event),
        };

        return $actor.' '.$verb;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'subject_id' => 'string', 'meta' => 'array'];
    }
}
