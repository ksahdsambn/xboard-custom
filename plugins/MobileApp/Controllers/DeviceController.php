<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\DeviceService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class DeviceController extends PluginController
{
    public function __construct(private readonly DeviceService $devices)
    {
    }

    public function upsert(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $json = $request->json()?->all() ?: [];
        $payload = array_merge($request->query(), $request->request->all(), is_array($json) ? $json : []);
        return MobileEnvelope::success($this->devices->register($this->user(), $payload));
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
