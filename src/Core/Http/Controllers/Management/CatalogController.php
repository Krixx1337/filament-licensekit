<?php

namespace LicenseKit\Core\Http\Controllers\Management;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LicenseKit\Core\Actions\ManagementQueries;
use LicenseKit\Core\Http\Resources\ManagementPlanResource;
use LicenseKit\Core\Http\Resources\ManagementProductResource;

class CatalogController extends Controller
{
    public function products(Request $request, ManagementQueries $queries): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'search' => ['sometimes', 'string', 'max:255']]);

        return ManagementProductResource::collection($queries->products($request->attributes->get('licensekit.connection'), $request->integer('per_page', 25), $request
            ->integer('page', 1), $request->input('search'))->withQueryString())->response();
    }

    public function plans(Request $request, ManagementQueries $queries): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'product_id' => ['sometimes', 'integer', 'min:1']]);

        return ManagementPlanResource::collection($queries->plans($request->attributes->get('licensekit.connection'), $request->integer('per_page', 25), $request->integer('page',
            1), $request->filled('product_id') ? $request->integer('product_id') : null)->withQueryString())->response();
    }
}
