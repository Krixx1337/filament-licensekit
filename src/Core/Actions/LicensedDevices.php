<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Data\DeviceAuthorization;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;

/**
 * Locks each license to one device (a fingerprint string sent by the customer's app).
 * The device is stored on the license row for that customer and product.
 */
class LicensedDevices
{
    public const MAXIMUM_DEVICE_BYTES = 1024;

    public function __construct(private AccessPolicy $access, private DeviceMatcher $matcher, private UserAccess $users, private Audit $audit) {}

    /** Checks access and the saved device without changing anything. */
    public function verify(Model $user, Product $product, #[\SensitiveParameter] string $device, ?CarbonImmutable $freeUntil = null): DeviceAuthorization
    {
        return $this->authorize($user, $product, $device, false, $freeUntil);
    }

    /** Checks access and saves the device on first use; a different device is rejected. */
    public function bind(Model $user, Product $product, #[\SensitiveParameter] string $device, ?CarbonImmutable $freeUntil = null): DeviceAuthorization
    {
        return DB::transaction(function () use ($user, $product, $device, $freeUntil): DeviceAuthorization {
            [$user, $product] = $this->lock($user, $product);

            return $this->authorize($user, $product, $device, true, $freeUntil);
        }, 3);
    }

    /** Customer self-service: moves the license to the device they are on now, subject to the product cooldown. */
    public function moveDevice(Model $user, Product $product, #[\SensitiveParameter] string $device, ?CarbonImmutable $freeUntil = null): DeviceAuthorization
    {
        $device = $this->validDevice($device);

        return DB::transaction(function () use ($user, $product, $device, $freeUntil): DeviceAuthorization {
            [$user, $product] = $this->lock($user, $product);
            $access = $this->access->authorize($user, $product, $freeUntil);
            $license = $this->license($user, $product);
            $this->assertCustomerMayChange($license, $product);
            $before = $license->device;
            $license->update(['device' => $device, 'device_bound_at' => now(), 'device_reset_at' => now()]);
            $this->changed($user, $product, $license, $user, 'device_moved', 'customer', $before, null);

            return new DeviceAuthorization($user, $access, $device);
        }, 3);
    }

    /** Customer self-service: clears the device so the next login locks the new one, subject to the product cooldown. */
    public function reset(Model $user, Product $product, ?string $reason = null): void
    {
        $reason = $this->reason($reason, false);
        DB::transaction(function () use ($user, $product, $reason): void {
            [$user, $product] = $this->lock($user, $product);
            if (! $this->users->available($user)) {
                throw new LicensingException('user_unavailable');
            }
            $license = $this->license($user, $product);
            $this->assertCustomerMayChange($license, $product);
            $this->clear($user, $product, $license, $user, 'customer', $reason);
        }, 3);
    }

    /** Staff override: clears the device immediately, ignoring the cooldown. */
    public function adminReset(Model $user, Product $product, Model $actor, string $reason): void
    {
        $reason = $this->reason($reason, true);
        DB::transaction(function () use ($user, $product, $actor, $reason): void {
            [$user, $product] = $this->lock($user, $product);
            $license = $this->license($user, $product);
            if ($license->device === null) {
                throw new LicensingException('device_not_bound', 409);
            }
            $this->clear($user, $product, $license, $actor, 'admin', $reason);
        }, 3);
    }

    /** @return array{allowed: bool, available_at: string|null, reason: string|null} */
    public function eligibility(Entitlement $license): array
    {
        $cooldown = $license->product->device_move_cooldown_days;
        if ($cooldown === null) {
            return ['allowed' => false, 'available_at' => null, 'reason' => 'customer_reset_disabled'];
        }
        $availableAt = $license->device_reset_at?->addDays($cooldown);
        $reason = $availableAt?->isFuture() ? 'reset_cooldown' : ($license->device === null ? 'device_not_bound' : null);

        return ['allowed' => $reason === null, 'available_at' => $availableAt?->toIso8601String(), 'reason' => $reason];
    }

    public function requiresDevice(Model $user, Product $product): bool
    {
        return $product->requires_hwid && ! ($this->users->available($user) && Gate::forUser($user)->allows('bypassHwid', $product));
    }

    private function authorize(Model $user, Product $product, string $device, bool $bind, ?CarbonImmutable $freeUntil): DeviceAuthorization
    {
        $access = $this->access->authorize($user, $product, $freeUntil);
        $device = $this->validDevice($device);
        if (! $this->requiresDevice($access->user, $access->product)) {
            return new DeviceAuthorization($access->user, $access, $device);
        }
        $license = $bind ? $this->license($access->user, $access->product)
            : Entitlement::where('user_id', $access->user->getKey())->where('product_id', $access->product->id)->first();
        if ($license?->device !== null) {
            if (! $this->matcher->matches($access->product, $license->device, $device)) {
                throw new LicensingException('hwid_mismatch');
            }
        } elseif ($bind && $license !== null) {
            $license->update(['device' => $device, 'device_bound_at' => now()]);
        } else {
            throw new LicensingException('device_not_bound', 409);
        }

        return new DeviceAuthorization($access->user, $access, $device);
    }

    private function assertCustomerMayChange(Entitlement $license, Product $product): void
    {
        $license->setRelation('product', $product);
        $failure = $this->eligibility($license)['reason'];
        if ($failure !== null) {
            throw new LicensingException($failure, match ($failure) {
                'reset_cooldown' => 429,
                'device_not_bound' => 409,
                default => 403,
            });
        }
    }

    private function clear(Model $user, Product $product, Entitlement $license, Model $actor, string $performedBy, ?string $reason): void
    {
        $before = $license->device;
        $license->update(['device' => null, 'device_bound_at' => null, 'device_reset_at' => now()]);
        $this->changed($user, $product, $license, $actor, 'device_reset', $performedBy, $before, $reason);
    }

    private function changed(Model $user, Product $product, Entitlement $license, Model $actor, string $event, string $performedBy, ?string $before, ?string $reason): void
    {
        Event::dispatch(new ProductAccessInvalidated($user, $product->id));
        $this->audit->record('user:'.$actor->getKey(), $event, $license, ['device' => $before], ['device' => $license->device, 'performed_by' => $performedBy], $reason);
    }

    /** The customer's license row for the product, created without paid time if needed (for example during a free weekend). */
    private function license(Model $user, Product $product): Entitlement
    {
        $license = Entitlement::where('user_id', $user->getKey())->where('product_id', $product->id)->lockForUpdate()->first()
            ?? Entitlement::create(['user_id' => $user->getKey(), 'product_id' => $product->id]);
        $license->setRelation('product', $product);

        return $license;
    }

    /** @return array{0: Model, 1: Product} */
    private function lock(Model $user, Product $product): array
    {
        return [
            $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first() ?? throw new LicensingException('user_unavailable'),
            Product::whereKey($product->id)->lockForUpdate()->first() ?? throw new LicensingException('service_unavailable'),
        ];
    }

    private function validDevice(#[\SensitiveParameter] string $device): string
    {
        if (trim($device) === '' || strlen($device) > self::MAXIMUM_DEVICE_BYTES || ! mb_check_encoding($device, 'UTF-8')) {
            throw new LicensingException('invalid_hwid', 422);
        }

        return $device;
    }

    private function reason(?string $reason, bool $required): ?string
    {
        $reason = trim($reason ?? '');
        if (mb_strlen($reason) > 2000) {
            throw new LicensingException('invalid_reset_reason', 422);
        }
        if ($required && $reason === '') {
            throw new LicensingException('reason_required', 422);
        }

        return $reason === '' ? null : $reason;
    }
}
