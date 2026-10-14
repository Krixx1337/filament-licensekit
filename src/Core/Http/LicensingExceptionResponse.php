<?php

namespace LicenseKit\Core\Http;

use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LicenseKit\Core\Exceptions\LicensingException;
use PDOException;

class LicensingExceptionResponse
{
    public function render(LicensingException $exception, Request $request): ?JsonResponse
    {
        if ($request->attributes->get('licensekit.http') !== true) {
            return null;
        }

        return response()->json(['error' => $exception->error, 'message' => $exception->getMessage()], $exception->status)->header('Cache-Control', 'private, no-store');
    }

    public function renderStorageFailure(PDOException|SQLiteDatabaseDoesNotExistException $exception, Request $request): ?JsonResponse
    {
        if ($request->attributes->get('licensekit.http') !== true) {
            return null;
        }

        return response()->json(['error' => 'licensing_unavailable', 'message' => 'licensing unavailable'], 503)->header('Cache-Control', 'private, no-store');
    }

    public function reportStorageFailure(PDOException|SQLiteDatabaseDoesNotExistException $exception): ?bool
    {
        if (request()->attributes->get('licensekit.http') !== true) {
            return null;
        }
        Log::error('LicenseKit storage unavailable', ['exception' => $exception::class, 'code' => $exception->getCode()]);

        return false;
    }
}
