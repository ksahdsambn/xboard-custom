<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\SecurityAuditService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class AdminSecurityAuditController extends PluginController
{
    public function __construct(private readonly SecurityAuditService $audits)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('perPage', 20);
        return MobileEnvelope::success($this->audits->list($page, $perPage));
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }
}
