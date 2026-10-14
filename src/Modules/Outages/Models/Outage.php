<?php

namespace LicenseKit\Modules\Outages\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Core\Models\Product;
use LicenseKit\Database\Factories\Outages\OutageFactory;

/**
 * @property int $id
 * @property int $product_id
 * @property string $reference
 * @property string $request_hash
 * @property bool $access_blocked
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $stopped_at
 * @property CarbonImmutable|null $ended_at
 * @property Product $product
 * @property LicensingOperation $operation
 */
#[Fillable(['product_id', 'reference', 'request_hash', 'reason', 'access_blocked', 'started_at', 'stopped_at', 'ended_at'])]
class Outage extends Model
{
    /** @use HasFactory<OutageFactory> */
    use HasFactory;

    protected $table = 'licensekit_outages';

    protected static function newFactory(): OutageFactory
    {
        return OutageFactory::new();
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasOne<LicensingOperation, $this> */
    public function operation(): HasOne
    {
        return $this->hasOne(LicensingOperation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['product_id' => 'integer', 'access_blocked' => 'boolean', 'started_at' => UtcDateTime::class, 'stopped_at' => UtcDateTime::class, 'ended_at' => UtcDateTime::class];
    }
}
