<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Adapters\NodeAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class NodeController extends PluginController
{
    public function __construct(private readonly NodeAdapter $nodes)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success(['items' => $this->nodes->listCompatible($this->user(), $this->claims($request))]);
    }

    private function user(): User
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user instanceof User) {
            throw new MobileApiException('AUTH_SESSION_INVALID', 403);
        }
        return $user;
    }

    private function claims(Request $request): array
    {
        $json = $request->json()?->all() ?: [];
        return array_merge($request->query(), $request->request->all(), is_array($json) ? $json : []);
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }
}
