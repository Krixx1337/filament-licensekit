<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LicenseKit\Core\Models\Product;

class ClientSessionController extends Controller
{
    public function login(ClientLoginRequest $request, Product $product, ClientSessions $devices): JsonResponse
    {
        $data = $request->validated();
        $result = $devices->login($product, $data['login'], $data['password'], $data['hwid']);
        $result['license'] = $result['license'] === null ? null : new LicenseResource($result['license']);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function heartbeat(ClientHeartbeatRequest $request, Product $product, ClientSessions $devices): JsonResponse
    {
        $data = $request->validated();
        $result = $devices->heartbeat($product, $request->bearerToken() ?? '', $data['hwid']);
        $result['license'] = $result['license'] === null ? null : new LicenseResource($result['license']);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }
}
