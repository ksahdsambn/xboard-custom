<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\MobileApp\Services\StartupConfigService;
use Plugin\MobileApp\Support\MobileClientHints;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class BootstrapController extends PluginController
{
    public function show(Request $request): JsonResponse
    {
        if (!PluginStatus::isEnabled()) {
            return MobileEnvelope::fail('SERVICE_MAINTENANCE', 503);
        }
        $payload = (new StartupConfigService())->bootstrapPayload(MobileClientHints::fromRequest($request));
        return MobileEnvelope::success($payload);
    }
}
