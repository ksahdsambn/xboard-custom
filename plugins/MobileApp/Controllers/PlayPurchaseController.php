<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Adapters\PlayDeveloperAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\PlayPurchaseService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class PlayPurchaseController extends PluginController
{
    public function submit(Request $request): JsonResponse
    {
        return MobileEnvelope::success($this->service()->submit($this->user(), $this->payload($request)));
    }

    public function restore(Request $request): JsonResponse
    {
        return MobileEnvelope::success($this->service()->restore($this->user(), $this->payload($request)));
    }

    private function payload(Request $request): array
    {
        $this->guardEnabled();
        $json = $request->json()?->all() ?: [];
        return array_merge($request->query(), $request->request->all(), is_array($json) ? $json : []);
    }

    private function service(): PlayPurchaseService
    {
        return new PlayPurchaseService(PlayDeveloperAdapter::shared(), new PlanService(new Plan()));
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
