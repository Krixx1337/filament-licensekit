<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\DeviceBan;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Models\Product;

class DeviceBans
{
    public function __construct(private ActivityLog $audit) {}

    public function assertAllowed(Product $product, string $device): void
    {
        if (DeviceBan::where('device_hash', hash('sha256', $device))
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))->exists()) {
            throw new LicensingException('device_banned');
        }
    }

    /** @return Collection<int, License> */
    public function sharedAccounts(License $license): Collection
    {
        return $this->exactDevice(License::query(), $license->device ?? '')->where('user_id', '!=', $license->user_id)
            ->with(['user', 'product'])->orderBy('id')->get();
    }

    /** @param list<int> $alsoSuspend */
    public function ban(License $license, Model $actor, bool $allProducts = false, bool $suspend = true, array $alsoSuspend = [], ?string $note = null): DeviceBan
    {
        return DB::transaction(function () use ($license, $actor, $allProducts, $suspend, $alsoSuspend, $note): DeviceBan {
            $license = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            if ($license->device === null) {
                throw new LicensingException('device_not_bound', 409);
            }
            $ban = DeviceBan::create(['device' => $license->device, 'device_hash' => hash('sha256', $license->device),
                'product_id' => $allProducts ? null : $license->product_id, 'note' => $note, 'created_by' => 'user:'.$actor->getKey()]);
            $targets = $this->exactDevice(License::query(), $license->device)->whereIn('id', [...$alsoSuspend, ...($suspend ? [$license->id] : [])])->orderBy('id')->get();
            foreach ($targets as $target) {
                if ($target->isRevoked()) {
                    continue;
                }
                app(ManageLicenses::class)->suspend($target, $actor, $note);
            }
            $this->exactDevice(LoaderSession::query(), $license->device)->when(! $allProducts, fn ($q) => $q->where('product_id', $license->product_id))->delete();
            $this->audit->record('user:'.$actor->getKey(), 'device_banned', $ban, ['before' => [], 'after' => ['product_id' => $ban->product_id], 'note' => $note]);

            return $ban;
        }, 3);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function exactDevice(Builder $query, string $device): Builder
    {
        return DB::getDriverName() === 'mysql' ? $query->whereRaw('BINARY device = ?', [$device]) : $query->where('device', $device);
    }

    public function unban(DeviceBan $ban, Model $actor): void
    {
        DB::transaction(function () use ($ban, $actor): void {
            $this->audit->record('user:'.$actor->getKey(), 'device_unbanned', $ban, ['before' => ['device' => $ban->device, 'product_id' => $ban->product_id], 'after' => [], 'note' => null]);
            $ban->delete();
        });
    }
}
