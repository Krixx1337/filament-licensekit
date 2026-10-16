<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;

class CatalogWrites
{
    /** @param class-string<Model> $model
     * @param array<string,mixed> $data */
    public static function create(string $model, array $data, ?Model $actor = null): Model
    {
        return DB::transaction(function () use ($model, $data, $actor): Model {
            $actor = self::actor($actor);
            if (! in_array($model, [Product::class, LicensePlan::class], true)) {
                throw new LicensingException('invalid_catalog_type', 422);
            }
            $prototype = new $model;
            $prototype->auditActor = 'user:'.$actor->getKey();
            $prototype->fill(array_intersect_key($data, array_flip($prototype->getFillable())));
            self::validate($prototype);
            $prototype->save();

            return $prototype;
        });
    }

    /** @param array<string,mixed> $data */
    public static function update(Model $record, array $data, ?Model $actor = null): Model
    {
        return DB::transaction(function () use ($record, $data, $actor): Model {
            $actor = self::actor($actor);
            if ($record instanceof LicensePlan) {
                Product::whereKey($record->product_id)->lockForUpdate()->firstOrFail();
            }
            $record = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record instanceof LicensePlan) {
                unset($data['product_id']);
            }
            if (! $record instanceof Product && ! $record instanceof LicensePlan) {
                throw new LicensingException('invalid_catalog_type', 422);
            }
            $record->auditActor = 'user:'.$actor->getKey();
            $record->fill(array_intersect_key($data, array_flip($record->getFillable())));
            self::validate($record);
            $record->save();

            return $record;
        });
    }

    private static function validate(Model $record): void
    {
        if ($record instanceof Product) {
            Validator::make($record->getAttributes(), [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255', Rule::unique('licensekit_products', 'slug')->ignore($record->getKey())],
                'status' => ['required', Rule::in(Product::STATUSES)],
                'requires_hwid' => ['required', 'boolean'],
                'device_move_cooldown_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            ])->validate();
        } elseif ($record instanceof LicensePlan) {
            Validator::make($record->getAttributes(), ['name' => ['required', 'string', 'max:255'], 'product_id' => ['required', 'integer', 'exists:licensekit_products,id'], 'is_enabled' => ['required', 'boolean']])->validate();
        }
    }

    private static function actor(?Model $actor): Model
    {
        return $actor ?? throw new LicensingException('admin_forbidden');
    }
}
