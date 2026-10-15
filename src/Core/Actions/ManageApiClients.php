<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Users\ConfiguredUserModel;

class ManageApiClients
{
    public function __construct(private Audit $audit) {}

    /** @param array<string, mixed> $data */
    public function save(?ApiClient $client, array $data, Model $actor): ApiClient
    {
        return DB::transaction(function () use ($client, $data, $actor): ApiClient {
            $record = $client === null ? new ApiClient : (ApiClient::whereKey($client->id)->lockForUpdate()->first() ?? throw new LicensingException('management_client_unavailable', 404));
            Validator::make($data, ['owner_user_id' => ['nullable', (new (ConfiguredUserModel::className()))->getKeyType() === 'int' ? 'integer' : 'string']])->validate();
            $actor = $this->authorize($actor, $client === null ? 'create' : 'update', $client === null ? ApiClient::class : $record, $data['owner_user_id'] ?? $record->owner_user_id);
            $before = $record->only($record->getFillable());
            $record->fill(array_intersect_key($data, array_flip($record->getFillable())));
            if ($record->all_products) {
                $record->allowed_product_ids = [];
            }
            Validator::make(['allowed_product_ids' => $record->allowed_product_ids], [
                'allowed_product_ids' => ['array', 'list'], 'allowed_product_ids.*' => ['integer', 'exists:licensekit_products,id'],
            ])->validate();
            $record->save();
            $this->audit->record('user:'.$actor->getKey(), $client === null ? 'management_client_created' : 'management_client_updated', $record, $client === null ? [] : $before, $record->only($record->getFillable()));

            return $record;
        }, 3);
    }

    public function rotate(ApiClient $client, CarbonImmutable $expiresAt, Model $actor, bool $allowDisabled = false): string
    {
        return DB::transaction(function () use ($client, $expiresAt, $actor, $allowDisabled): string {
            $client = ApiClient::whereKey($client->id)->lockForUpdate()->first() ?? throw new LicensingException('management_client_unavailable', 404);
            $actor = $this->authorize($actor, 'rotateToken', $client);
            if ($client->retired_at !== null || (! $allowDisabled && ! $client->is_enabled)) {
                throw new LicensingException('management_client_disabled');
            }
            $expiresAt = $expiresAt->utc()->startOfSecond();
            if (! $expiresAt->isFuture()) {
                throw new LicensingException('invalid_token_expiry', 422);
            }
            $client->tokens()->delete();
            $token = $client->createToken('licensekit:api', ['licensekit:api', ...$client->permissions], $expiresAt)->plainTextToken;
            $this->audit->record('user:'.$actor->getKey(), 'management_token_rotated', $client, [], ['expires_at' => $expiresAt->toIso8601String()]);

            return $token;
        }, 3);
    }

    public function revoke(ApiClient $client, Model $actor): void
    {
        DB::transaction(function () use ($client, $actor): void {
            $client = ApiClient::whereKey($client->id)->lockForUpdate()->first() ?? throw new LicensingException('management_client_unavailable', 404);
            $actor = $this->authorize($actor, 'revokeTokens', $client);
            $client->tokens()->delete();
            $this->audit->record('user:'.$actor->getKey(), 'management_tokens_revoked', $client);
        }, 3);
    }

    public function retire(ApiClient $client, Model $actor, string $reason): void
    {
        DB::transaction(function () use ($client, $actor, $reason): void {
            $client = ApiClient::whereKey($client->id)->lockForUpdate()->first() ?? throw new LicensingException('management_client_unavailable', 404);
            $actor = $this->authorize($actor, 'retire', $client, $client->owner_user_id);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
            if ($client->retired_at !== null) {
                return;
            }
            $before = $client->only(['owner_user_id', 'is_enabled']);
            $client->tokens()->delete();
            $client->retired_at = CarbonImmutable::now('UTC');
            $client->is_enabled = false;
            $client->owner_user_id = null;
            $client->save();
            $this->audit->record('user:'.$actor->getKey(), 'management_client_retired', $client, $before,
                ['owner_user_id' => null, 'is_enabled' => false, 'retired_at' => $client->retired_at->toIso8601String()], $reason);
        }, 3);
    }

    /** @param class-string<Model>|Model $subject */
    private function authorize(Model $actor, string $ability, string|Model $subject, int|string|null $ownerId = null): Model
    {
        $users = $actor->newQuery()->whereKey(array_values(array_unique(array_filter([$actor->getKey(), $ownerId], fn ($id) => $id !== null))))
            ->orderBy($actor->getKeyName())->sharedLock()->get();
        $actor = $users->first(fn (Model $user): bool => (string) $user->getKey() === (string) $actor->getKey()) ?? throw new LicensingException('management_forbidden');
        if (! app(AdminAuthorization::class)->allows($actor, $ability, $subject)) {
            throw new LicensingException('management_forbidden');
        }

        return $actor;
    }
}
