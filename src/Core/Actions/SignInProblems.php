<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;

/** Remembers why a customer's app was refused, so support can answer "why can't I sign in?". */
class SignInProblems
{
    public const EVENT = 'access_denied';

    /** Apps retry and heartbeat a lot, so the same problem is logged once per this many minutes. */
    public const QUIET_MINUTES = 10;

    /** Ended sessions are routine, not a customer problem. */
    private const IGNORED = ['invalid_token'];

    /** Each refusal in plain words, for staff. */
    private const REASONS = [
        'invalid_credentials' => 'wrong password', 'no_license' => 'no license yet', 'expired' => 'license expired', 'paused' => 'license paused',
        'suspended' => 'license suspended', 'cancelled' => 'license cancelled', 'user_unavailable' => 'account unavailable',
        'email_not_verified' => 'email not verified', 'maintenance' => 'product in maintenance', 'device_mismatch' => 'locked to another PC',
        'device_banned' => 'banned PC', 'device_not_bound' => 'no PC locked yet', 'move_cooldown' => 'PC move on cooldown',
        'customer_reset_disabled' => 'PC moves turned off', 'invalid_device' => 'bad PC fingerprint', 'invalid_key' => 'invalid key',
        'key_already_used' => 'key already used',
    ];

    public function __construct(private ActivityLog $audit) {}

    /** Call it outside a transaction that is about to roll back, or the entry is rolled back too. */
    public function record(Model $user, Product $product, string $code, #[\SensitiveParameter] ?string $device = null): void
    {
        if (in_array($code, self::IGNORED, true)) {
            return;
        }
        $subject = License::where('user_id', $user->getKey())->where('product_id', $product->id)->first() ?? $user;
        $repeat = ActivityEntry::query()->where('event', self::EVENT)->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())->where('meta->product_id', $product->id)->where('meta->code', $code)
            ->where('created_at', '>=', now()->subMinutes(self::QUIET_MINUTES))->exists();
        if (! $repeat) {
            $this->audit->record('user:'.$user->getKey(), self::EVENT, $subject, ['product_id' => $product->id, 'code' => $code, 'device' => $device]);
        }
    }

    public static function reason(string $code): string
    {
        return self::REASONS[$code] ?? str_replace('_', ' ', $code);
    }

    /** @return Collection<int, ActivityEntry> */
    public function recent(License $license, int $limit = 10): Collection
    {
        return ActivityEntry::query()->where('event', self::EVENT)->where('subject_type', $license->getMorphClass())
            ->where('subject_id', (string) $license->id)->latest('id')->limit($limit)->get();
    }
}
