<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Core\Users\UserAccess;

class CustomerLicenses
{
    public function __construct(private UserAccess $users, private CustomerLicenseSummary $summary) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(Model $user, int $perPage = 25): LengthAwarePaginator
    {
        if ($perPage < 1 || $perPage > 100) {
            throw new LicensingException('invalid_page_size', 422);
        }

        $page = $this->query($user)->orderBy('id')->paginate($perPage)->appends(['per_page' => $perPage]);

        return $page->through(fn (License $license): array => $this->summary->for($license));
    }

    /** @return array<string, mixed> */
    public function find(Model $user, int|string $id): array
    {
        return $this->summary->for($this->query($user)->whereKey($id)->firstOrFail());
    }

    /** @return Builder<License> */
    private function query(Model $user): Builder
    {
        $model = ConfiguredUserModel::className();
        $user = $user instanceof $model ? $user->fresh() : null;
        if ($user === null || $this->users->suspended($user)) {
            throw new LicensingException('user_unavailable');
        }

        return License::query()->where('user_id', $user->getKey())->with(['product', 'user']);
    }
}
