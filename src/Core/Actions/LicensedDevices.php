<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Data\DeviceAuthorization;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;

class LicensedDevices
{
    public function __construct(private AccessPolicy $access, private Hwids $hwids, private DeviceBindings $bindings, private Audit $audit) {}

    /** @param array<string, mixed> $hwid */
    public function verify(Model $user, Product $product, #[\SensitiveParameter] array $hwid, ?CarbonImmutable $freeUntil = null): DeviceAuthorization
    {
        return $this->authorize($user, $product, $hwid, false, $freeUntil);
    }

    /** @param array<string, mixed> $hwid */
    public function bind(Model $user, Product $product, #[\SensitiveParameter] array $hwid, ?CarbonImmutable $freeUntil = null): DeviceAuthorization
    {
        return DB::transaction(function () use ($user, $product, $hwid, $freeUntil): DeviceAuthorization {
            $user = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first() ?? throw new LicensingException('user_unavailable');
            $product = Product::whereKey($product->id)->lockForUpdate()->first() ?? throw new LicensingException('service_unavailable');

            return $this->authorize($user, $product, $hwid, true, $freeUntil);
        }, 3);
    }

    /** @param array<string, mixed> $hwid */
    public function upgrade(Model $user, Product $product, #[\SensitiveParameter] array $hwid, ?CarbonImmutable $freeUntil = null): DeviceAuthorization
    {
        return DB::transaction(function () use ($user, $product, $hwid, $freeUntil): DeviceAuthorization {
            $user = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first() ?? throw new LicensingException('user_unavailable');
            $product = Product::whereKey($product->id)->lockForUpdate()->first() ?? throw new LicensingException('service_unavailable');
            $access = $this->access->authorize($user, $product, $freeUntil);
            $binding = $this->bindings->find($access->user, $access->product) ?? throw new LicensingException('device_not_bound', 409);
            $bound = $binding->hwid ?? throw new LicensingException('device_not_bound', 409);
            $normalized = $this->hwids->normalize($access->product, $hwid);
            $this->hwids->handler->assertAllowed($access->user, $access->product, $bound, $normalized);
            if ($bound !== $normalized) {
                $this->hwids->verify($access->product, $bound, $normalized);
                if (! $this->hwids->handler->canUpgrade($access->product, $bound, $normalized)) {
                    throw new LicensingException('hwid_upgrade_denied', 409);
                }
                $binding->update(['hwid' => $normalized]);
                $this->audit->record('user:'.$user->getKey(), 'hwid_upgraded', $binding, [], ['scope_key' => $binding->scope_key]);
                Event::dispatch(new ProductAccessInvalidated($user, $binding->scope_key === 'global' ? null : $product->id));
            }

            return new DeviceAuthorization($access->user, $access, $normalized);
        }, 3);
    }

    /** @param array<string, mixed> $hwid */
    private function authorize(Model $user, Product $product, array $hwid, bool $bind, ?CarbonImmutable $freeUntil): DeviceAuthorization
    {
        $access = $this->access->authorize($user, $product, $freeUntil);
        $normalized = $this->hwids->normalize($access->product, $hwid);
        $this->bindings->verifyOrBind($access->user, $access->product, $normalized, $bind);

        return new DeviceAuthorization($access->user, $access, $normalized);
    }

    public function reset(Entitlement $entitlement, Model $actor, ?string $reason = null): void
    {
        if ($entitlement->user === null) {
            throw new LicensingException('user_unavailable');
        }
        $this->bindings->reset($entitlement->user, $entitlement->product, $actor, $reason);
    }

    public function adminReset(Entitlement $entitlement, Model $actor, string $reason): void
    {
        if ($entitlement->user === null) {
            throw new LicensingException('user_unavailable');
        }
        $this->bindings->adminReset($entitlement->user, $entitlement->product, $actor, $reason);
    }
}
