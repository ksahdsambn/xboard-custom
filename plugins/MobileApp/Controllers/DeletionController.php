<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\AccountDeletionService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class DeletionController extends PluginController
{
    public function __construct(private readonly AccountDeletionService $deletions)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->deletions->preview($this->user()));
    }

    public function submit(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->deletions->execute($this->user(), $this->payload($request)));
    }

    public function show(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->deletions->status($this->user()));
    }

    private function payload(Request $request): array
    {
        $json = $request->json()?->all() ?: [];
        return array_merge($request->query(), $request->request->all(), is_array($json) ? $json : []);
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
