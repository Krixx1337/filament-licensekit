<?php

namespace LicenseKit\Filament;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Actions\CatalogWrites as CoreCatalogWrites;

/** Panel adapter. Non-panel callers use Core\Actions\CatalogWrites with an explicit actor. */
class CatalogWrites
{
    /** @param class-string<Model> $model
     * @param array<string, mixed> $data */
    public static function create(string $model, array $data, ?Model $actor = null): Model
    {
        return CoreCatalogWrites::create($model, $data, $actor ?? AdminAccess::actor());
    }

    /** @param array<string, mixed> $data */
    public static function update(Model $record, array $data, ?Model $actor = null): Model
    {
        return CoreCatalogWrites::update($record, $data, $actor ?? AdminAccess::actor());
    }
}
