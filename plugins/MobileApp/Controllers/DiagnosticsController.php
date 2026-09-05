<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobileClientHints;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\MobileRequestId;
use Plugin\MobileApp\Support\PluginStatus;

class DiagnosticsController extends PluginController
{
    public function show(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $this->user();
        $hints = MobileClientHints::fromRequest($request);
        return MobileEnvelope::success([
            'mobileApiVersion' => MobileEnvelope::apiVersionFromRequest(),
            'profileSchemaVersion' => 1,
            'appVersion' => $hints->appVersion,
            'androidApi' => $hints->androidApi,
            'libxrayVersion' => $hints->libxrayVersion,
            'xrayCoreVersion' => $hints->xrayCoreVersion,
            'requestId' => MobileRequestId::resolve($request),
        ]);
    }

    private function user(): User
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user instanceof User) {
            throw new MobileApiException('AUTH_SESSION_INVALID', 403);
        }
        return $user;
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }
}
