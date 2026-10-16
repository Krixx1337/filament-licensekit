<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;

class ManageApiClients
{
    public function __construct(private Audit $audit) {}

    /** @param array<string, mixed> $data */
    public function save(?ApiClient $client, array $data, Model $actor): ApiClient
    {
        return DB::transaction(function () use ($client, $data, $actor): ApiClient {
            $record = $client === null ? new ApiClient : (ApiClient::whereKey($client->id)->lockForUpdate()->first() ?? throw new LicensingException('management_client_unavailable', 404));
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
            if (! $allowDisabled && ! $client->is_enabled) {
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
            $client->tokens()->delete();
            $this->audit->record('user:'.$actor->getKey(), 'management_tokens_revoked', $client);
        }, 3);
    }
}
