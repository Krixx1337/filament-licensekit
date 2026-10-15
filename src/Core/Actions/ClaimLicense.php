<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;
use LicenseKit\Support\OutageState;

class ClaimLicense
{
    public function __construct(private SerialKey $serial, private EntitlementClock $clock, private UserAccess $users, private Audit $audit, private LicensingBans $bans, private OutageState $outages, private AccessRestrictions $restrictions) {}

    public function claimFor(Model $actor, Model $user, LicenseKey $key): Entitlement
    {
        return DB::transaction(function () use ($actor, $user, $key): Entitlement {
            $users = $user->newQuery()->whereKey([$user->getKey(), $actor->getKey()])->orderBy($user->getKeyName())->lockForUpdate()->get();
            $actor = $users->first(fn (Model $locked): bool => (string) $locked->getKey() === (string) $actor->getKey()) ?? throw new LicensingException('admin_forbidden');
            $user = $users->first(fn (Model $locked): bool => (string) $locked->getKey() === (string) $user->getKey()) ?? throw new LicensingException('user_unavailable');
            Product::whereKey($key->plan->product_id)->lockForUpdate()->firstOrFail();
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->first() ?? throw new LicensingException('invalid_key', 422);
            if (! app(AdminAuthorization::class)->allows($actor, 'claim', $key)) {
                throw new LicensingException('admin_forbidden');
            }

            return $this->claimLocked($user, $key, 'user:'.$actor->getKey());
        }, 3);
    }

    public function claim(Model $user, #[\SensitiveParameter] string $value, ?string $actor = null): Entitlement
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

    private function claimLocked(Model $user, LicenseKey $key, ?string $actor): Entitlement
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
        $this->outages->assertClaim($key->plan->product_id);
        $this->bans->assertAllowed($user, $key->plan->product);
        $ownership = ['user_id' => $user->getKey(), 'product_id' => $key->plan->product_id];
        $entitlement = Entitlement::where($ownership)->lockForUpdate()->first();
        $this->restrictions->assertAllowed($user, $key->plan->product, $entitlement);
        $entitlement ??= Entitlement::create($ownership);
        if ($entitlement->paused_at !== null) {
            throw new LicensingException('subscription_paused');
        }
        if ($entitlement->is_revoked) {
            throw new LicensingException('entitlement_revoked');
        }
        if ($entitlement->is_lifetime) {
            throw new LicensingException('already_lifetime', 409);
        }
        $before = $entitlement->only(['expires_at', 'is_lifetime']);
        $this->clock->grant($entitlement, LicenseDuration::seconds($key->getRawOriginal('duration_days')));
        Event::dispatch(new EntitlementGranted($entitlement, LicenseDuration::seconds($key->getRawOriginal('duration_days')), CarbonImmutable::now('UTC')->startOfSecond()));
        $key->update(['entitlement_id' => $entitlement->id, 'claimed_at' => now()]);
        $this->audit->record($actor ?? 'user:'.$user->getKey(), 'key_claimed', $entitlement, $before,
            [...$entitlement->only(['expires_at', 'is_lifetime']), 'key_id' => $key->id]);

        return $entitlement;
    }
}
