<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Database\Factories\Core\LicenseKeyFactory;

/**
 * @property int $id
 * @property int $batch_id
 * @property int|null $license_id
 * @property string $key
 * @property string|null $note
 * @property CarbonImmutable|null $redeemed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property License|null $license
 * @property Batch $batch
 */
class LicenseKey extends Model
{
    protected $fillable = ['batch_id', 'license_id', 'key', 'note', 'redeemed_at', 'cancelled_at'];

    protected $hidden = ['key', 'note'];

    protected $table = 'licensekit_keys';

    protected static function newFactory(): LicenseKeyFactory
    {
        return LicenseKeyFactory::new();
    }

    /** @use HasFactory<LicenseKeyFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'batch_id' => 'integer', 'license_id' => 'integer', 'redeemed_at' => UtcDateTime::class,
            'cancelled_at' => UtcDateTime::class];
    }

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
