<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Models\ProductGroup;
use LicenseKit\Core\Users\UserAccess;

class CatalogWrites
{
    public static function deleteGroup(ProductGroup $group, ?Model $actor = null): bool
    {
        return DB::transaction(function () use ($group, $actor): bool {
            $actor = self::actor($actor);
            $group = $group->fresh() ?? abort(404);
            self::authorize($actor, 'delete', $group);
            $actor = 'user:'.$actor->getKey();
            $before = ['name' => $group->name, 'affected_product_count' => $group->products()->count()];
            $deleted = $group->delete();
            if ($deleted !== true) {
                return false;
            }
            app(Audit::class)->record($actor, 'configuration_deleted', $group, $before);

            return true;
        });
    }

    /** @param class-string<Model> $model
     * @param array<string,mixed> $data */
    public static function create(string $model, array $data, ?Model $actor = null): Model
    {
        return DB::transaction(function () use ($model, $data, $actor): Model {
            $actor = self::actor($actor);
            if (! in_array($model, [Product::class, LicensePlan::class, ProductGroup::class], true)) {
                throw new LicensingException('invalid_catalog_type', 422);
            }
            self::authorize($actor, 'create', $model);
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
            if (! $record instanceof Product && ! $record instanceof LicensePlan && ! $record instanceof ProductGroup) {
                throw new LicensingException('invalid_catalog_type', 422);
            }
            self::authorize($actor, 'update', $record);
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
                'is_enabled' => ['required', 'boolean'], 'is_blocked' => ['required', 'boolean'],
                'product_group_id' => ['nullable', 'integer', 'exists:licensekit_product_groups,id'],
            ])->validate();
        } elseif ($record instanceof LicensePlan) {
            Validator::make($record->getAttributes(), ['name' => ['required', 'string', 'max:255'], 'product_id' => ['required', 'integer', 'exists:licensekit_products,id'], 'is_enabled' => ['required', 'boolean']])->validate();
        }
    }

    /** @param class-string<Model>|Model $subject */
    private static function authorize(Model $actor, string $ability, string|Model $subject): void
    {
        if (! app(AdminAuthorization::class)->allows($actor, $ability, $subject)) {
            throw new LicensingException('admin_forbidden');
        }
    }

    private static function actor(?Model $actor): Model
    {
        $actor ??= throw new LicensingException('admin_forbidden');
        $actor = $actor->newQuery()->whereKey($actor->getKey())->lockForUpdate()->first() ?? throw new LicensingException('admin_forbidden');
        if (! app(UserAccess::class)->available($actor)) {
            throw new LicensingException('admin_forbidden');
        }

        return $actor;
    }
}
