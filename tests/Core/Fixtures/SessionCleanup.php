<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use LicenseKit\Core\Events\LicenseAccessInvalidated;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Models\License;

class SessionCleanup
{
    public function productInvalidated(ProductAccessInvalidated $event): void
    {
        DB::transaction(fn () => $this->forUser($event->user, $event->productId));
    }

    public function accessInvalidated(LicenseAccessInvalidated $event): void
    {
        DB::transaction(fn () => $this->license($event->license));
    }

    public function license(License $license): void
    {
        if ($license->user !== null) {
            $this->forUser($license->user, $license->product_id);
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
