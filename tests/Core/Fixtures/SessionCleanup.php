<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use LicenseKit\Core\Events\EntitlementAccessInvalidated;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Models\Entitlement;

class SessionCleanup
{
    public function productInvalidated(ProductAccessInvalidated $event): void
    {
        DB::transaction(fn () => $this->forUser($event->user, $event->productId));
    }

    public function accessInvalidated(EntitlementAccessInvalidated $event): void
    {
        DB::transaction(fn () => $this->entitlement($event->entitlement));
    }

    public function entitlement(Entitlement $entitlement): void
    {
        if ($entitlement->user !== null) {
            $this->forUser($entitlement->user, $entitlement->product_id);
        }
    }

    public function forUser(Model $user, ?int $productId = null): void
    {
        $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();
        if (! Schema::hasTable('client_sessions')) {
            return;
        }
        foreach (ClientSession::where('user_id', $user->getKey())->when($productId !== null, fn ($query) => $query->where('product_id', $productId))->lockForUpdate()->get() as $session) {
            if (Schema::hasTable((new (Sanctum::personalAccessTokenModel()))->getTable())) {
                $session->tokens()->delete();
            }
            $session->delete();
        }
    }
}
