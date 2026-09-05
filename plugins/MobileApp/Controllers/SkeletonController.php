<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class SkeletonController extends PluginController
{
    public function notImplemented(): JsonResponse
    {
        if (!PluginStatus::isEnabled()) {
            return MobileEnvelope::fail('SERVICE_MAINTENANCE', 404, 'MobileApp plugin is not enabled');
        }

        return MobileEnvelope::fail(
            'OPERATION_NOT_IMPLEMENTED',
            501,
            'MobileApp skeleton; business implementation starts at TASK-019'
        );
    }
}
