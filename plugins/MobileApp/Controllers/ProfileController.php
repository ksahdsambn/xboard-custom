<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\ProfileService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class ProfileController extends PluginController
{
    public function __construct(private readonly ProfileService $profiles)
    {
    }

    public function show(Request $request, string $opaqueProfileId): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success(
            $this->profiles->forOpaqueId($this->user(), $opaqueProfileId, $this->schemaVersion($request), $this->claims($request))
        );
    }

    private function schemaVersion(Request $request): ?int
    {
        $raw = $request->headers->get('X-Profile-Schema-Version', $request->query('schemaVersion'));
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            throw new MobileApiException('PROFILE_SCHEMA_UNSUPPORTED');
        }
        return (int) $raw;
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
