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

    /** What each event means, as it reads after the person's name. */
    public const EVENTS = [
        'key_redeemed' => 'redeemed a key', 'keys_generated' => 'generated keys', 'key_cancelled' => 'cancelled a key',
        'batch_unused_cancelled' => 'cancelled unused keys', 'key_note_updated' => 'edited a key note',
        'license_extended' => 'added time', 'product_time_added' => 'added time to a product', 'license_paused' => 'paused a license',
        'license_resumed' => 'resumed a license', 'license_status_changed' => 'changed a license status', 'license_note_updated' => 'edited a license note',
        'device_moved' => 'moved to a new PC', 'device_reset' => 'reset the PC', 'device_banned' => 'banned a PC', 'device_unbanned' => 'unbanned a PC',
        'product_created' => 'created a product', 'product_saved' => 'updated a product',
        'connection_saved' => 'saved a store connection', 'connection_token_regenerated' => 'made a new store token',
    ];

    public function description(): string
    {
        return $this->summary().' on '.class_basename($this->subject_type).' #'.$this->subject_id;
    }

    /** Who did what, without the subject: "admin@example.com added time". */
    public function summary(): string
    {
        $actor = $this->meta['actor_label'] ?? match ($this->actor) {
            'system' => 'LicenseKit',
            'api' => 'Your store',
            'app' => 'Your app',
            default => $this->actor,
        };
        $verb = self::EVENTS[$this->event] ?? str_replace('_', ' ', $this->event);

        return $actor.' '.$verb;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'subject_id' => 'string', 'meta' => 'array'];
    }
}
