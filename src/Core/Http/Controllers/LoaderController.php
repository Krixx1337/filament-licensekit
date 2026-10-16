<?php

namespace LicenseKit\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LicenseKit\Core\Actions\LoaderSessions;
use LicenseKit\Core\Http\Requests\LoaderRequest;
use LicenseKit\Core\Models\Product;

class LoaderController
{
    public function login(LoaderRequest $request, Product $product, LoaderSessions $sessions): JsonResponse
    {
        $data = $request->validated();

        return response()->json($sessions->login($product, $data['login'], $data['password'], $data['device']));
    }

    public function heartbeat(LoaderRequest $request, Product $product, LoaderSessions $sessions): JsonResponse
    {
        $data = $request->validated();

        return response()->json($sessions->heartbeat($product, $data['token'], $data['device']));
    }

    public function redeem(LoaderRequest $request, Product $product, LoaderSessions $sessions): JsonResponse
    {
        $data = $request->validated();

        return response()->json($sessions->redeem($product, $data['token'], $data['key']));
    }

    public function moveDevice(LoaderRequest $request, Product $product, LoaderSessions $sessions): JsonResponse
    {
        $data = $request->validated();

        return response()->json($sessions->login($product, $data['login'], $data['password'], $data['device'], move: true));
    }
}
