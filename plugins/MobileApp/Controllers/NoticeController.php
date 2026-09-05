<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Adapters\NoticeAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class NoticeController extends PluginController
{
    public function __construct(private readonly NoticeAdapter $notices)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('perPage', 20);
        return MobileEnvelope::success($this->notices->list($this->user(), $page, $perPage));
    }

    public function show(Request $request, string $noticeId): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->notices->detail($this->user(), $noticeId));
    }

    public function read(Request $request, string $noticeId): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->notices->markRead($this->user(), $noticeId));
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
