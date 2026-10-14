<?php

namespace LicenseKit\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Filament\AdminAccess;
use UnitEnum;

abstract class LicensingResource extends Resource
{
    protected static bool $editable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Licensing';

    protected static bool $isScopedToTenant = false;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof BackedEnum ? $action->value : ($action instanceof UnitEnum ? $action->name : $action);
        $allowed = match ($ability) {
            'viewAny' => static::canViewAny(),
            'view' => $record !== null && static::canView($record),
            'create' => static::canCreate(),
            'update' => $record !== null && static::canEdit($record),
            'delete' => $record !== null && static::canDelete($record),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::can('viewAny', static::getModel(), static::class);
    }

    public static function canView(Model $record): bool
    {
        return AdminAccess::can('view', $record, static::class);
    }

    public static function canCreate(): bool
    {
        return static::$editable && AdminAccess::can('create', static::getModel(), static::class);
    }

    public static function canEdit(Model $record): bool
    {
        return static::$editable && AdminAccess::can('update', $record, static::class);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }
}
