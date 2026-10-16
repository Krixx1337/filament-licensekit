<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use LicenseKit\Core\Contracts\ReadinessCheck;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Users\UserAccess;

class CustomerLicenses
{
    public function __construct(private UserAccess $users, private ReadinessCheck $readiness, private CustomerLicenseSummary $summary) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(Model $user, int $perPage = 25): LengthAwarePaginator
    {
        if ($perPage < 1 || $perPage > 100) {
            throw new LicensingException('invalid_page_size', 422);
        }

        $page = $this->query($user)->orderBy('id')->paginate($perPage)->appends(['per_page' => $perPage]);

        return $page->through(fn (Entitlement $entitlement): array => $this->summary->for($entitlement));
    }

    /** @return array<string, mixed> */
    public function find(Model $user, int|string $id): array
    {
        return $this->summary->for($this->query($user)->whereKey($id)->firstOrFail());
    }

    /** @return Builder<Entitlement> */
    private function query(Model $user): Builder
    {
        $this->readiness->assertReady();
        $user = $user->fresh();
        if ($user === null || ! $this->users->available($user)) {
            throw new LicensingException('user_unavailable');
        }

        return Entitlement::query()->where('user_id', $user->getKey())->with(['product', 'user']);
    }
}
