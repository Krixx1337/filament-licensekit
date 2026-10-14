<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Events\EntitlementAccessInvalidated;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;

class ManageLicensingBans
{
    public function __construct(private Audit $audit) {}

    public function create(Model $user, Product $product, string $reason, Model $actor, ?DateTimeInterface $expiresAt = null): LicensingBan
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($user, $product, $reason, $actor, $expiresAt): LicensingBan {
            $userModel = ConfiguredUserModel::className();
            if (! $user instanceof $userModel) {
                throw new LicensingException('user_unavailable');
            }
            $user->newQuery()->whereKey([$user->getKey(), $actor->getKey()])->orderBy($user->getKeyName())->lockForUpdate()->get();
            $actor = $this->authorize($actor, 'create', LicensingBan::class);
            $user = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first() ?? throw new LicensingException('user_unavailable');
            $product = $product->fresh() ?? throw new LicensingException('service_unavailable');
            $reason = $this->reason($reason);
            $expiry = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt)->utc()->startOfSecond();
            if ($expiry !== null && $expiry->lessThanOrEqualTo(now('UTC'))) {
                throw ValidationException::withMessages(['expires_at' => 'The expiry must be a future UTC time.']);
            }
            if (LicensingBan::where('user_id', $user->getKey())->where('product_id', $product->id)->active()->lockForUpdate()->first() !== null) {
                throw new LicensingException('ban_already_active', 409);
            }
            $ban = LicensingBan::create(['user_id' => $user->getKey(), 'product_id' => $product->id, 'reason' => $reason,
                'created_by' => 'user:'.$actor->getKey(), 'expires_at' => $expiry]);
            Event::dispatch(new ProductAccessInvalidated($user, $product->id));
            $entitlement = Entitlement::where('user_id', $user->getKey())->where('product_id', $product->id)->first();
            if ($entitlement !== null) {
                Event::dispatch(new EntitlementAccessInvalidated($entitlement));
            }
            $this->audit->record('user:'.$actor->getKey(), 'licensing_ban_created', $ban, [], $ban->only(['user_id', 'product_id', 'expires_at']), $reason);

            return $ban;
        });
    }

    public function lift(LicensingBan $ban, string $reason, Model $actor): LicensingBan
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($ban, $reason, $actor): LicensingBan {
            $actor->newQuery()->whereKey(array_filter([$actor->getKey(), $ban->user_id], fn ($id): bool => $id !== null))->orderBy($actor->getKeyName())->lockForUpdate()->get();
            if ($ban->user_id !== null) {
                ConfiguredUserModel::className()::whereKey($ban->user_id)->lockForUpdate()->first();
            }
            $ban = LicensingBan::whereKey($ban->id)->lockForUpdate()->first() ?? throw new LicensingException('ban_unavailable', 404);
            $actor = $this->authorize($actor, 'lift', $ban);
            if ($ban->lifted_at !== null) {
                return $ban;
            }
            $reason = $this->reason($reason);
            $ban->update(['lifted_at' => now('UTC'), 'lifted_by' => 'user:'.$actor->getKey(), 'lift_reason' => $reason]);
            $this->audit->record('user:'.$actor->getKey(), 'licensing_ban_lifted', $ban, ['lifted_at' => null], $ban->only(['lifted_at', 'lifted_by']), $reason);

            return $ban;
        });
    }

    private function assertEnabled(): void
    {
        if (config('licensekit.features.licensing_bans') !== true) {
            throw new LicensingException('licensing_bans_disabled', 403);
        }
    }

    /** @param class-string<Model>|Model $subject */
    private function authorize(Model $actor, string $ability, string|Model $subject): Model
    {
        $userModel = ConfiguredUserModel::className();
        if (! $actor instanceof $userModel) {
            throw new LicensingException('ban_forbidden');
        }
        $actor = $actor->newQuery()->whereKey($actor->getKey())->sharedLock()->first() ?? throw new LicensingException('ban_forbidden');
        if (! app(AdminAuthorization::class)->allows($actor, $ability, $subject)) {
            throw new LicensingException('ban_forbidden');
        }

        return $actor;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();

        return $reason;
    }
}
