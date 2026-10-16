<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Core\Users\UserAccess;

class DeviceBindings
{
    public function __construct(private AccessPolicy $access, private Hwids $hwids, private UserAccess $users, private Audit $audit) {}

    public function scopeKey(Product $product): string
    {
        return config('licensekit.device_binding.scope') === 'global' ? 'global' : 'product:'.$product->id;
    }

    /** @internal Evaluate with current user and product records after access authorization. */
    public function requiresBinding(Model $user, Product $product): bool
    {
        return $product->requires_hwid && ! ($this->users->available($user) && Gate::forUser($user)->allows('bypassHwid', $product));
    }

    public function find(Model $user, Product $product): ?DeviceBinding
    {
        return DeviceBinding::where('user_id', $user->getKey())->where('scope_key', $this->scopeKey($product))->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first();
    }

    /** @param Collection<int, Entitlement> $entitlements */
    public function preload(Collection $entitlements): void
    {
        $bindings = DeviceBinding::whereIn('user_id', $entitlements->pluck('user_id')->unique()->all())
            ->whereIn('scope_key', $entitlements->map(fn (Entitlement $entitlement): string => $this->scopeKey($entitlement->product))->unique()->all())
            ->get()->keyBy(fn (DeviceBinding $binding): string => $binding->user_id.'|'.$binding->scope_key);
        foreach ($entitlements as $entitlement) {
            $entitlement->setRelation('binding', $bindings->get($entitlement->user_id.'|'.$this->scopeKey($entitlement->product)));
        }
    }

    /**
     * @internal Used by LicensedDevices after access authorization, inside the licensing transaction when binding.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function verifyOrBind(Model $user, Product $product, array $normalized, bool $bind): void
    {
        $binding = $this->find($user, $product);
        $bound = $binding?->hwid;
        $this->hwids->handler->assertAllowed($user, $product, $bound, $normalized);
        if (! $this->requiresBinding($user, $product)) {
            return;
        }
        if ($bound !== null) {
            $this->hwids->verify($product, $bound, $normalized);
        } elseif ($bind) {
            $binding ??= new DeviceBinding(['user_id' => $user->getKey(), 'scope_key' => $this->scopeKey($product)]);
            $binding->fill(['hwid' => $normalized, 'bound_at' => now()])->save();
        } else {
            throw new LicensingException('device_not_bound', 409);
        }
    }

    /** @param array<string, mixed> $hwid */
    public function verify(Model $user, Product $product, #[\SensitiveParameter] array $hwid): void
    {
        $user = $user->fresh() ?? throw new LicensingException('user_unavailable');
        $product = $product->fresh() ?? throw new LicensingException('service_unavailable');
        $this->access->assertAllowed($user, $product);
        $this->verifyOrBind($user, $product, $this->hwids->normalize($product, $hwid), false);
    }

    /**
     * Host must approve an access grant before binding.
     *
     * @param  array<string, mixed>  $hwid
     */
    public function bind(Model $user, Product $product, #[\SensitiveParameter] array $hwid): void
    {
        DB::transaction(function () use ($user, $product, $hwid): void {
            $user = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first() ?? throw new LicensingException('user_unavailable');
            $product = Product::whereKey($product->id)->lockForUpdate()->first() ?? throw new LicensingException('service_unavailable');
            $this->access->assertAllowed($user, $product);
            $this->verifyOrBind($user, $product, $this->hwids->normalize($product, $hwid), true);
        }, 3);
    }

    /** @return array{allowed: bool, available_at: string|null, reason: string|null} */
    public function eligibility(?DeviceBinding $binding): array
    {
        if (config('licensekit.device_binding.customer_reset.enabled') !== true) {
            return ['allowed' => false, 'available_at' => null, 'reason' => 'customer_reset_disabled'];
        }
        $availableAt = $binding?->last_hwid_reset_at?->setTimezone(config('app.timezone', 'UTC'))->addDays(config('licensekit.device_binding.customer_reset.cooldown_days'));
        $reason = $availableAt?->isFuture() ? 'reset_cooldown' : ($binding?->hwid === null ? 'device_not_bound' : null);

        return ['allowed' => $reason === null, 'available_at' => $availableAt?->toIso8601String(), 'reason' => $reason];
    }

    public function reset(Model $user, Product $product, Model $actor, ?string $reason = null): void
    {
        $this->resetBinding($user, $product, $actor, $reason, false);
    }

    public function adminReset(Model $user, Product $product, Model $actor, string $reason): void
    {
        $this->resetBinding($user, $product, $actor, $reason, true);
    }

    private function resetBinding(Model $user, Product $product, Model $actor, ?string $reason, bool $admin): void
    {
        $reason = trim($reason ?? '');
        if (mb_strlen($reason) > 2000) {
            throw new LicensingException('invalid_reset_reason', 422);
        }
        $reason = $reason === '' ? null : $reason;
        DB::transaction(function () use ($user, $product, $actor, $reason, $admin): void {
            if (! Schema::hasTable('licensekit_device_reset_history')) {
                throw new LicensingException('licensing_unavailable', 503);
            }
            $userModel = ConfiguredUserModel::className();
            if (! $user instanceof $userModel) {
                throw new LicensingException('user_unavailable');
            }
            if (! $actor instanceof $userModel) {
                throw new LicensingException('reset_forbidden');
            }
            $user->newQuery()->whereKey([$user->getKey(), $actor->getKey()])->orderBy($user->getKeyName())->lockForUpdate()->get();
            $actor = $actor->newQuery()->whereKey($actor->getKey())->sharedLock()->first() ?? throw new LicensingException('user_unavailable');
            $user = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first() ?? throw new LicensingException('user_unavailable');
            $product = $product->fresh() ?? throw new LicensingException('service_unavailable');
            if (! $admin && (! $this->users->available($actor) || (string) $user->getKey() !== (string) $actor->getKey())) {
                throw new LicensingException('reset_forbidden');
            }
            if (! $admin && config('licensekit.device_binding.customer_reset.enabled') !== true) {
                throw new LicensingException('customer_reset_disabled', 403);
            }
            if (($admin || config('licensekit.device_binding.customer_reset.require_reason')) && $reason === null) {
                throw new LicensingException('reason_required', 422);
            }
            $binding = $this->find($user, $product);
            if (! $admin && ($failure = $this->eligibility($binding)['reason']) !== null) {
                throw new LicensingException($failure, $failure === 'reset_cooldown' ? 429 : 409);
            }
            if ($binding === null || $binding->hwid === null) {
                throw new LicensingException('device_not_bound', 409);
            }
            $resetAt = now();
            $global = $binding->scope_key === 'global';
            $beforeHwid = $binding->hwid;
            $binding->update(['hwid' => null, 'bound_at' => null, 'last_hwid_reset_at' => $resetAt]);
            DeviceResetHistory::create([
                'user_id' => (string) $user->getKey(), 'scope' => $global ? 'global' : 'per_product',
                'product_id' => $global ? null : $product->id, 'reset_at' => $resetAt,
                'before_hwid' => $beforeHwid, 'performed_by' => $admin ? 'admin' : 'customer', 'actor_id' => (string) $actor->getKey(), 'reason' => $reason,
            ]);
            Event::dispatch(new ProductAccessInvalidated($user, $binding->scope_key === 'global' ? null : $product->id));
            $this->audit->record('user:'.$actor->getKey(), 'hwid_reset', $binding, [], ['scope_key' => $binding->scope_key], $reason);
        });
    }
}
