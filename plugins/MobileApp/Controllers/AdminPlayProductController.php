<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\PlayProductService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class AdminPlayProductController extends PluginController
{
    public function index(): JsonResponse
    {
        $this->guardEnabled();
        $items = $this->service()->listAdmin();
        return MobileEnvelope::success(['items' => $items]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $payload = $request->json()?->all() ?: [];
        if (!is_array($payload) || $payload === []) {
            $payload = $request->all();
        }
        $actor = Auth::guard('sanctum')->id();
        $row = $this->service()->upsert($payload, is_numeric($actor) ? (int) $actor : null);
        return MobileEnvelope::success([
            'id' => (int) $row->id,
            'enabled' => (bool) $row->enabled,
        ]);
    }

    private function service(): PlayProductService
    {
        return new PlayProductService(new PlanService(new \App\Models\Plan()));
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }
}
