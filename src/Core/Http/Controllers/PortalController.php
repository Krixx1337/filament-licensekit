<?php

namespace LicenseKit\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CustomerLicenses;
use LicenseKit\Core\Actions\CustomerLicenseSummary;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Users\ConfiguredUserModel;

class PortalController
{
    public function index(Request $request, CustomerLicenses $licenses): JsonResponse
    {
        return response()->json($licenses->paginate($request->user(ConfiguredUserModel::guard())));
    }

    public function redeem(Request $request, ClaimLicense $claims, CustomerLicenseSummary $summary): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:64']]);
        $license = $claims->claim($request->user(ConfiguredUserModel::guard()), $data['key']);

        return response()->json($summary->for($license));
    }

    public function reset(Request $request, int $license, LicensedDevices $devices): JsonResponse
    {
        $user = $request->user(ConfiguredUserModel::guard());
        $license = License::where('user_id', $user->getKey())->findOrFail($license);
        $devices->reset($user, $license->product);

        return response()->json(['message' => 'Device reset. Your next app login will lock your new PC.']);
    }
}
