<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Data\ClaimProvenance;
use LicenseKit\Core\Events\LicenseClaimed;
use LicenseKit\Core\Events\LicenseGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;

class ClaimLicense
{
    public function __construct(private SerialKey $serial, private LicenseClock $clock, private UserAccess $users, private Audit $audit, private AccessRestrictions $restrictions) {}

    public function claimFor(Model $actor, Model $user, LicenseKey $key): License
    {
        return DB::transaction(function () use ($actor, $user, $key): License {
            $users = $user->newQuery()->whereKey([$user->getKey(), $actor->getKey()])->orderBy($user->getKeyName())->lockForUpdate()->get();
            $actor = $users->first(fn (Model $locked): bool => (string) $locked->getKey() === (string) $actor->getKey()) ?? throw new LicensingException('admin_forbidden');
            $user = $users->first(fn (Model $locked): bool => (string) $locked->getKey() === (string) $user->getKey()) ?? throw new LicensingException('user_unavailable');
            Product::whereKey($key->plan->product_id)->lockForUpdate()->firstOrFail();
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->first() ?? throw new LicensingException('invalid_key', 422);

            return $this->claimLocked($user, $key, 'user:'.$actor->getKey());
        }, 3);
    }

    public function claim(Model $user, #[\SensitiveParameter] string $value, ?string $actor = null): License
    {
        return DB::transaction(function () use ($user, $value, $actor) {
            $user = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if (! $this->users->available($user)) {
                throw new LicensingException('user_unavailable');
            }
            $value = $this->serial->normalize($value);
            $candidate = LicenseKey::where('key', $value)->first();
            if ($candidate === null) {
                throw new LicensingException('invalid_key', 422);
            }
            Product::whereKey($candidate->plan->product_id)->lockForUpdate()->firstOrFail();
            $key = LicenseKey::whereKey($candidate->id)->lockForUpdate()->first();
            if ($key === null) {
                throw new LicensingException('invalid_key', 422);
            }

            return $this->claimLocked($user, $key, $actor);
        }, 3);
    }

    private function claimLocked(Model $user, LicenseKey $key, ?string $actor): License
    {
        if (! $this->users->available($user)) {
            throw new LicensingException('user_unavailable');
        }
        if ($key->revoked_at !== null) {
            throw new LicensingException('invalid_key', 422);
        }
        if ($key->claimed_at !== null) {
            throw new LicensingException('key_already_claimed', 409);
        }
        $ownership = ['user_id' => $user->getKey(), 'product_id' => $key->plan->product_id];
        $license = License::where($ownership)->lockForUpdate()->first();
        $this->restrictions->assertAllowed($user, $key->plan->product, $license);
        if ($license?->isSuspended()) {
            throw new LicensingException('license_suspended');
        }
        $license ??= License::create($ownership);
        if ($license->paused_at !== null) {
            throw new LicensingException('subscription_paused');
        }
        if ($license->isRevoked()) {
            throw new LicensingException('license_revoked');
        }
        if ($license->is_lifetime) {
            throw new LicensingException('already_lifetime', 409);
        }
        $before = $license->only(['expires_at', 'is_lifetime']);
        $claimedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $this->clock->grant($license, LicenseDuration::seconds($key->getRawOriginal('duration_days')));
        $snapshot = fn (array $state): array => array_map(fn ($value) => $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->utc()->toIso8601String() : $value, $state);
        $provenance = new ClaimProvenance($key->id, $key->batch_id, $key->batch->issuer,
            $key->batch->reference, 'claim:'.$key->id, $license->id, $user->getKey(), $license->product_id,
            $claimedAt, $snapshot($before), $snapshot($license->only(['expires_at', 'is_lifetime'])));
        Event::dispatch(new LicenseGranted($license, LicenseDuration::seconds($key->getRawOriginal('duration_days')), $claimedAt, $provenance));
        $key->update(['license_id' => $license->id, 'claimed_at' => $claimedAt]);
        $license->refresh();
        $completed = new ClaimProvenance($key->id, $key->batch_id, $key->batch->issuer,
            $key->batch->reference, 'claim:'.$key->id, $license->id, $user->getKey(), $license->product_id,
            $claimedAt, $snapshot($before), $snapshot($license->only(['expires_at', 'is_lifetime'])));
        Event::dispatch(new LicenseClaimed($completed));
        $this->audit->record($actor ?? 'user:'.$user->getKey(), 'key_claimed', $license, $before,
            [...$license->only(['expires_at', 'is_lifetime']), 'key_id' => $key->id]);

        return $license;
    }
}
