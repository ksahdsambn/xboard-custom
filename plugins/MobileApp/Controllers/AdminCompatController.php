<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Services\StartupConfigService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class AdminCompatController extends PluginController
{
    public function show(): JsonResponse
    {
        if (!PluginStatus::isEnabled()) {
            return MobileEnvelope::fail('SERVICE_MAINTENANCE', 503);
        }
        $row = (new StartupConfigService())->settings();
        return MobileEnvelope::success([
            'minimumAppVersion' => $row->minimum_app_version,
            'disabledKernelVersions' => $row->disabled_kernel_versions ?? [],
            'purchaseEnabled' => (bool) $row->purchase_enabled,
            'maintenance' => (bool) $row->maintenance,
            'forceUpgradeEnabled' => (bool) $row->force_upgrade_enabled,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if (!PluginStatus::isEnabled()) {
            return MobileEnvelope::fail('SERVICE_MAINTENANCE', 503);
        }
        $actor = Auth::guard('sanctum')->id();
        $payload = $request->json()->all();
        if ($payload === []) {
            $payload = $request->all();
        }
        (new StartupConfigService())->update($payload, is_numeric($actor) ? (int) $actor : null);
        return MobileEnvelope::success(['updated' => true]);
    }
}
