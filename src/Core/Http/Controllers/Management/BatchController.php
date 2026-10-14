<?php

namespace LicenseKit\Core\Http\Controllers\Management;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LicenseKit\Core\Actions\ManagementAccess;
use LicenseKit\Core\Actions\ManagementOperations;
use LicenseKit\Core\Actions\ManagementQueries;
use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Http\Requests\ManagementWriteRequest;
use LicenseKit\Core\Http\Resources\ManagementBatchResource;

class BatchController extends Controller
{
    public function store(ManagementWriteRequest $request, ManagementAccess $access, ManagementOperations $operations): JsonResponse
    {
        return $access->run($request->bearerToken() ?? '', 'licenses:create', function (ManagementAuthorization $context) use ($request, $operations): JsonResponse {
            $batch = $operations->create($request->bearerToken() ?? '', $request->integer('plan_id'), $request->integer('quantity'),
                $request->string('reference')->toString(),
                $request->input('label') === null ? null : $request->string('label')->toString());

            return (new ManagementBatchResource($batch))->includingKeys($context->allows('keys:read'))->response();
        });
    }

    public function index(Request $request, ManagementQueries $queries): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'plan_id' => ['sometimes', 'integer', 'min:1'], 'reference' => ['sometimes', 'string', 'max:128']]);

        return ManagementBatchResource::collection($queries->batches($request->bearerToken() ?? '', $request->integer('per_page', 25), $request->integer('page', 1), $request->filled('plan_id') ? $request->integer('plan_id') : null, $request->input('reference'))->withQueryString())->response();
    }

    public function show(Request $request, int $batch, ManagementQueries $queries): JsonResponse
    {
        return (new ManagementBatchResource($queries->batch($request->bearerToken() ?? '', $batch)))->response();
    }

    public function keys(Request $request, int $batch, ManagementQueries $queries): JsonResponse
    {
        return response()->json(['data' => $queries->keys($request->bearerToken() ?? '', $batch)]);
    }
}
