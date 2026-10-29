<?php

namespace LicenseKit\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Navigation;
use UnitEnum;

abstract class LicensingResource extends Resource
{
    protected static bool $editable = false;

    /** Support staff may browse resources marked AdminAccess::SUPPORT; everything else is admin-only. */
    protected static string $access = AdminAccess::ADMIN;

    /** Resources spanning every product, which staff limited to some products never see. */
    protected static bool $global = false;

    protected static string|UnitEnum|null $navigationGroup = Navigation::LICENSING;

    protected static bool $isScopedToTenant = false;

    /** The LicenseKit role gate comes first; host model policies (for example from Shield) still apply after it. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof BackedEnum ? $action->value : ($action instanceof UnitEnum ? $action->name : $action);
        $allowed = match ($ability) {
            'viewAny', 'view' => static::$access === AdminAccess::SUPPORT ? AdminAccess::isSupport() : AdminAccess::isAdmin(),
            'create', 'update' => static::$editable && AdminAccess::isAdmin(),
            default => false,
        };
        $allowed = $allowed && (! static::$global || ! AdminAccess::isScoped())
            && ($record === null || AdminAccess::allowsRecord($record));

        return $allowed ? parent::getAuthorizationResponse($action, $record) : Response::deny();
    }
}
