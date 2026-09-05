<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Adapters\PlanAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class PlanController extends PluginController
{
    public function __construct(private readonly PlanAdapter $plans)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->guardEnabled();
        if (!$this->user() instanceof User) {
            throw new MobileApiException('AUTH_SESSION_INVALID', 403);
        }
        $json = $request->json()?->all() ?: [];
        $claims = array_merge($request->query(), $request->request->all(), is_array($json) ? $json : []);
        return MobileEnvelope::success(['items' => $this->plans->listSellablePlayPlans($claims)]);
    }

    private function user(): ?User
    {
        $user = Auth::guard('sanctum')->user();
        return $user instanceof User ? $user : null;
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }
}
