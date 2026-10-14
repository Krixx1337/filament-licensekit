<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Database\Factories\Core\LicensingBanFactory;

/**
 * @property int $id
 * @property int|string|null $user_id
 * @property int $product_id
 * @property string $reason
 * @property string $created_by
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $lifted_at
 * @property string|null $lifted_by
 * @property string|null $lift_reason
 * @property Model|null $user
 * @property Product $product
 */
#[Fillable(['user_id', 'product_id', 'reason', 'created_by', 'expires_at', 'lifted_at', 'lifted_by', 'lift_reason'])]
class LicensingBan extends Model
{
    /** @use HasFactory<LicensingBanFactory> */
    use HasFactory;

    protected $table = 'licensekit_licensing_bans';

    protected static function newFactory(): LicensingBanFactory
    {
        return LicensingBanFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (LicensingBan $ban): void {
            if (array_diff(array_keys($ban->getDirty()), ['lifted_at', 'lifted_by', 'lift_reason', 'updated_at']) !== []
                || $ban->getOriginal('lifted_at') !== null) {
                throw new LicensingException('ban_is_immutable', 409);
            }
        });
        static::deleting(function (LicensingBan $ban): void {
            throw new LicensingException('ban_history_required', 409);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['product_id' => 'integer', 'expires_at' => UtcDateTime::class, 'lifted_at' => UtcDateTime::class];
    }

    /** @param Builder<LicensingBan> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('lifted_at')->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')));
    }

    public function status(): string
    {
        return $this->lifted_at !== null ? 'Lifted' : ($this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now('UTC')) ? 'Expired' : 'Active');
    }

    /** @return BelongsTo<Model&Authenticatable, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ConfiguredUserModel::className(), 'user_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
