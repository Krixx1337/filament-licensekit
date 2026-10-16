<?php

namespace LicenseKit\Core\Http\Controllers\Management;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\ManagementQueries;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Http\Requests\ManagementWriteRequest;
use LicenseKit\Core\Http\Resources\ManagementBatchResource;
use LicenseKit\Core\Models\LicensePlan;

class BatchController extends Controller
{
    public function store(ManagementWriteRequest $request, Keys $keys): JsonResponse
    {
        $context = $request->attributes->get('licensekit.connection');
        $plan = LicensePlan::findOrFail($request->integer('plan_id'));
        abort_unless($context->includesProduct($plan->product_id), 404);
        abort_if($request->integer('quantity') > $context->client->max_per_request, 403);
        $batch = $keys->issue($plan, $request->integer('quantity'), new IssueContext(
            source: 'api', reference: $request->string('reference')->toString(), connection: $context->client, label: $request->input('label')));

        return (new ManagementBatchResource($batch))->includingKeys(true)->response();
    }

    public function index(Request $request, ManagementQueries $queries): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'plan_id' => ['sometimes', 'integer', 'min:1'],
            'reference' => ['sometimes', 'string', 'max:128']]);

        return ManagementBatchResource::collection($queries->batches($request->attributes->get('licensekit.connection'), $request->integer('per_page', 25), $request
            ->integer('page', 1), $request->filled('plan_id') ? $request->integer('plan_id') : null, $request->input('reference'))->withQueryString())->response();
    }

    public function show(Request $request, int $batch, ManagementQueries $queries): JsonResponse
    {
        return (new ManagementBatchResource($queries->batch($request->attributes->get('licensekit.connection'), $batch)))->response();
    }

    public function keys(Request $request, int $batch, ManagementQueries $queries): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:all,unused,redeemed,cancelled']]);

        return JsonResource::collection($queries->keys($request->attributes->get('licensekit.connection'), $batch, $request->integer('per_page', 25),
            $request->integer('page', 1), $request->string('status', 'all')->toString())->withQueryString())->response();
    }
}
