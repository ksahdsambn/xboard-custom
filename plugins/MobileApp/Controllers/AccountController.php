<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Adapters\AccountAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\EntitlementService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class AccountController extends PluginController
{
    public function __construct(
        private readonly AccountAdapter $accounts,
        private readonly EntitlementService $entitlements
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->accounts->snapshot($this->user(), $this->claims($request)));
    }

    public function entitlement(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->entitlements->forUser($this->user(), $this->claims($request)));
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
