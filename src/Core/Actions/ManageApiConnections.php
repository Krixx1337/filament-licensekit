<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Core\Users\ConfiguredUserModel;

class ManageApiConnections
{
    public function __construct(private ActivityLog $audit) {}

    /** @param array<string, mixed> $data */
    public function save(?ApiConnection $connection, array $data, Model $actor): ApiConnection
    {
        $data = Validator::make($data, ['user_id' => ['nullable', Rule::exists(ConfiguredUserModel::className(), (new (ConfiguredUserModel::className()))->getKeyName())],
            'name' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean'],
            'allowed_product_ids' => ['nullable', 'array', 'list'], 'allowed_product_ids.*' => ['integer', 'distinct', 'exists:licensekit_products,id'],
            'max_per_request' => ['required', 'integer', 'min:1', 'max:'.config('licensekit.max_batch_size')]])->validate();

        return DB::transaction(function () use ($connection, $data, $actor): ApiConnection {
            $record = $connection === null ? new ApiConnection : ApiConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $before = $record->only($record->getFillable());
            $record->fill($data)->save();
            $this->audit->record('user:'.$actor->getKey(), 'connection_saved', $record, ['before' => $before, 'after' => $data, 'note' => null]);

            return $record;
        });
    }

    public function regenerate(ApiConnection $connection, Model $actor): string
    {
        return DB::transaction(function () use ($connection, $actor): string {
            $connection = ApiConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $token = bin2hex(random_bytes(32));
            $connection->forceFill(['token_hash' => hash('sha256', $token)])->save();
            $this->audit->record('user:'.$actor->getKey(), 'connection_token_regenerated', $connection);

            return $token;
        });
    }
}
