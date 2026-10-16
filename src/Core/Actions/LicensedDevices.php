<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Data\DeviceAuthorization;
use LicenseKit\Core\Events\AccessRevoked;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;

/**
 * Locks each license to one device (a fingerprint string sent by the customer's app).
 * The device is stored on the license row for that customer and product.
 */
class LicensedDevices
{
    public const MAXIMUM_DEVICE_BYTES = 1024;

    public function __construct(private AccessPolicy $access, private DeviceMatcher $matcher, private UserAccess $users, private ActivityLog $audit, private DeviceBans $bans) {}

    /** Checks access and the saved device without changing anything. */
    public function verify(Model $user, Product $product, #[\SensitiveParameter] string $device): DeviceAuthorization
    {
        return $this->authorize($user, $product, $device, false);
    }

    /** Checks access and saves the device on first use; a different device is rejected. */
    public function bind(Model $user, Product $product, #[\SensitiveParameter] string $device): DeviceAuthorization
    {
        return DB::transaction(function () use ($user, $product, $device): DeviceAuthorization {
            [$user, $product] = $this->lock($user, $product);

            return $this->authorize($user, $product, $device, true);
        }, 3);
    }

    /** Customer self-service: moves the license to the device they are on now, subject to the product cooldown. */
    public function moveDevice(Model $user, Product $product, #[\SensitiveParameter] string $device): License
    {
        $device = $this->validDevice($device);
        $this->bans->assertAllowed($product, $device);

        return DB::transaction(function () use ($user, $product, $device): License {
            [$user, $product] = $this->lock($user, $product);
            $this->access->assertAllowed($user, $product);
            $license = $this->license($user, $product);
            $this->assertCustomerMayChange($license, $product);
            $before = $license->device;
            $license->update(['device' => $device, 'device_bound_at' => now(), 'device_reset_at' => now()]);
            $this->changed($user, $product, $license, $user, 'device_moved', 'customer', $before, null);

            return $license;
        }, 3);
    }

    /** Customer self-service: clears the device so the next login locks the new one, subject to the product cooldown. */
    public function reset(Model $user, Product $product, ?string $reason = null): void
    {
        $reason = $this->reason($reason);
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
    public function adminReset(Model $user, Product $product, Model $actor, ?string $reason = null): void
    {
        $reason = $this->reason($reason);
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
    public function eligibility(License $license): array
    {
        $cooldown = $license->product->device_move_cooldown_days;
        if ($cooldown === null) {
            return ['allowed' => false, 'available_at' => null, 'reason' => 'customer_reset_disabled'];
        }
        $availableAt = $license->device_reset_at?->addDays($cooldown);
        $reason = $availableAt?->isFuture() ? 'move_cooldown' : ($license->device === null ? 'device_not_bound' : null);

        return ['allowed' => $reason === null, 'available_at' => $availableAt?->toIso8601String(), 'reason' => $reason];
    }

    public function requiresDevice(Model $user, Product $product): bool
    {
        return $product->device_lock;
    }

    private function authorize(Model $user, Product $product, string $device, bool $bind): DeviceAuthorization
    {
        $access = $this->access->authorize($user, $product);
        $device = $this->validDevice($device);
        $this->bans->assertAllowed($product, $device);
        $license = $bind ? $this->license($access->user, $access->product)
            : License::where('user_id', $access->user->getKey())->where('product_id', $access->product->id)->first();
        if (! $this->requiresDevice($access->user, $access->product)) {
            return new DeviceAuthorization($access->user, $access, $device);
        }
        if ($license?->device !== null) {
            if (! $this->matcher->matches($access->product, $license->device, $device)) {
                throw new LicensingException('device_mismatch');
            }
        } elseif ($bind && $license !== null) {
            $license->update(['device' => $device, 'device_bound_at' => now()]);
            $this->audit->record('user:'.$access->user->getKey(), 'device_locked', $license, ['before' => ['device' => null],
                'after' => ['device' => $device, 'performed_by' => 'customer'], 'note' => null]);
        } else {
            throw new LicensingException('device_not_bound', 409);
        }

        return new DeviceAuthorization($access->user, $access, $device);
    }

    private function assertCustomerMayChange(License $license, Product $product): void
    {
        $license->setRelation('product', $product);
        $failure = $this->eligibility($license)['reason'];
        if ($failure !== null) {
            throw new LicensingException($failure, match ($failure) {
                'move_cooldown' => 429,
                'device_not_bound' => 409,
                default => 403,
            });
        }
    }

    private function clear(Model $user, Product $product, License $license, Model $actor, string $performedBy, ?string $reason): void
    {
        $before = $license->device;
        $license->update(['device' => null, 'device_bound_at' => null, 'device_reset_at' => now()]);
        $this->changed($user, $product, $license, $actor, 'device_reset', $performedBy, $before, $reason);
    }

    private function changed(Model $user, Product $product, License $license, Model $actor, string $event, string $performedBy, ?string $before, ?string $reason): void
    {
        Event::dispatch(new AccessRevoked($license));
        $this->audit->record('user:'.$actor->getKey(), $event, $license, ['before' => ['device' => $before], 'after' => ['device' => $license->device,
            'performed_by' => $performedBy], 'note' => $reason]);
    }

    /** The customer's license row for the product, created without paid time if needed (for example during a free weekend). */
    private function license(Model $user, Product $product): License
    {
        $license = License::where('user_id', $user->getKey())->where('product_id', $product->id)->lockForUpdate()->first()
            ?? License::createOrFirst(['user_id' => $user->getKey(), 'product_id' => $product->id]);
        $license->setRelation('product', $product);

        return $license;
    }

    /** @return array{0: Model, 1: Product} */
    private function lock(Model $user, Product $product): array
    {
        return [
            $user->newQuery()->whereKey($user->getKey())->first() ?? throw new LicensingException('user_unavailable'),
            Product::whereKey($product->id)->first() ?? throw new LicensingException('maintenance'),
        ];
    }

    private function validDevice(#[\SensitiveParameter] string $device): string
    {
        if (trim($device) === '' || strlen($device) > self::MAXIMUM_DEVICE_BYTES || ! mb_check_encoding($device, 'UTF-8')) {
            throw new LicensingException('invalid_device', 422);
        }

        return $device;
    }

    private function reason(?string $reason): ?string
    {
        $reason = trim($reason ?? '');
        if (mb_strlen($reason) > 2000) {
            throw new LicensingException('invalid_reset_reason', 422);
        }

        return $reason === '' ? null : $reason;
    }
}
