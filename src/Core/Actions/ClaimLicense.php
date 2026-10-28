<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Events\KeyRedeemed;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Users\UserAccess;

class ClaimLicense
{
    public function __construct(private SerialKey $serial, private LicenseClock $clock, private UserAccess $users, private ActivityLog $audit) {}

    public function claimFor(Model $actor, Model $user, LicenseKey $key): License
    {
        return $this->claim($user, $key->key, 'user:'.$actor->getKey());
    }

    public function claim(Model $user, #[\SensitiveParameter] string $value, ?string $actor = null): License
    {
        $value = $this->serial->normalize($value);
        $operation = function () use ($user, $value, $actor): License {
            $user = $user->fresh() ?? throw new LicensingException('user_unavailable');
            if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
                throw new LicensingException('email_not_verified');
            }
            if (! $this->users->available($user)) {
                throw new LicensingException('user_unavailable');
            }
            $key = LicenseKey::where('key', $value)->lockForUpdate()->first() ?? throw new LicensingException('invalid_key', 422);
            if ($key->cancelled_at !== null) {
                throw new LicensingException('invalid_key', 422);
            }
            if ($key->redeemed_at !== null) {
                throw new LicensingException('key_already_used', 409);
            }
            $ownership = ['user_id' => $user->getKey(), 'product_id' => $key->batch->plan->product_id];
            $license = License::where($ownership)->lockForUpdate()->first() ?? License::create($ownership);
            if ($license->isSuspended() || $license->isRevoked()) {
                throw new LicensingException($license->isSuspended() ? 'suspended' : 'cancelled');
            }
            if ($license->is_lifetime) {
                throw new LicensingException('already_lifetime', 409);
            }
            $days = $key->batch->getRawOriginal('duration_days');
            if (! config('licensekit.stack_keys') && $days !== null && ($license->expires_at?->isFuture() || $license->remaining_seconds > 0)) {
                throw new LicensingException('license_still_active');
            }
            $before = $license->only(['expires_at', 'is_lifetime', 'remaining_seconds', 'status']);
            $this->clock->grant($license, LicenseDuration::seconds($days));
            $key->update(['license_id' => $license->id, 'redeemed_at' => now()]);
            $this->audit->record($actor ?? 'user:'.$user->getKey(), 'key_redeemed', $license, ['before' => $before, 'after' => [...$license->only(['expires_at', 'is_lifetime',
                'remaining_seconds', 'status']), 'key_id' => $key->id], 'note' => null]);
            Event::dispatch(new KeyRedeemed($key->id, $license->id));

            return $license;
        };
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction($operation, 3);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 2) {
                    throw $exception;
                }
            }
        }
    }
}
