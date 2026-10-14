<?php

namespace LicenseKit\Modules\Outages\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Core\Models\Product;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $outage_id
 * @property string $kind
 * @property string $reference
 * @property string $request_hash
 * @property string $status
 * @property string $actor
 * @property int|null $seconds
 * @property CarbonImmutable|null $eligible_at
 * @property int $target_count
 * @property int $credited_count
 * @property int $skipped_count
 * @property Outage|null $outage
 */
#[Fillable(['product_id', 'outage_id', 'kind', 'reference', 'request_hash', 'status', 'seconds', 'eligible_at', 'reason', 'actor', 'target_count', 'credited_count', 'skipped_count', 'failure', 'applied_at'])]
class LicensingOperation extends Model
{
    protected $table = 'licensekit_compensation_operations';

    /** @return BelongsTo<Outage, $this> */
    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['product_id' => 'integer', 'outage_id' => 'integer', 'seconds' => 'integer', 'eligible_at' => UtcDateTime::class, 'applied_at' => UtcDateTime::class, 'target_count' => 'integer', 'credited_count' => 'integer', 'skipped_count' => 'integer'];
    }
}
