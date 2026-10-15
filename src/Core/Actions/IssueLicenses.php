<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Data\IssuanceResult;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;

class IssueLicenses
{
    public function __construct(private CreateBatch $batches) {}

    public function issue(string $integration, int $planId, int $quantity, string $reference, ?string $label = null, bool $discloseSerials = false, ?Model $reseller = null): IssuanceResult
    {
        $settings = config('licensekit.local_integrations');
        $settings = is_array($settings) ? ($settings[$integration] ?? null) : null;
        if (! preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $integration) || ! is_array($settings)
            || ($settings['enabled'] ?? false) !== true || ! is_int($settings['maximum_quantity'] ?? null)
            || $quantity < 1 || $quantity > $settings['maximum_quantity']
            || $quantity > config('licensekit.maximum_batch_size')
            || ($discloseSerials && ($settings['disclose_serials'] ?? false) !== true)) {
            throw new LicensingException('integration_forbidden');
        }

        return DB::transaction(function () use ($integration, $planId, $quantity, $reference, $label, $discloseSerials, $settings, $reseller): IssuanceResult {
            $userModel = ConfiguredUserModel::className();
            if ($reseller instanceof $userModel) {
                $userModel::whereKey($reseller->getKey())->sharedLock()->first();
            }
            $plan = LicensePlan::find($planId) ?? throw new LicensingException('plan_unavailable');
            Product::whereKey($plan->product_id)->lockForUpdate()->firstOrFail();
            $allowed = $settings['allowed_product_ids'] ?? [];
            if (($settings['all_products'] ?? false) !== true && (! is_array($allowed) || ! in_array($plan->product_id, $allowed, true))) {
                throw new LicensingException('integration_forbidden');
            }
            $issuer = 'local:'.$integration;
            $batch = $this->batches->create($plan, $quantity, $reference, $issuer, $label, reseller: $reseller);

            $ids = [];
            $serials = [];
            foreach ($batch->keys as $key) {
                $ids[] = $key->id;
                if ($discloseSerials) {
                    $serials[] = $key->key;
                }
            }

            return new IssuanceResult($batch->id, $ids, ! $batch->wasRecentlyCreated, $discloseSerials ? $serials : null);
        }, 3);
    }
}
