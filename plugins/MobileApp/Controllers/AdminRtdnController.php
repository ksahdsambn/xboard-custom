<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\RtdnService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class AdminRtdnController extends PluginController
{
    public function index(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('perPage', 20);
        return MobileEnvelope::success(RtdnService::make()->auditView($page, $perPage));
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }
}
