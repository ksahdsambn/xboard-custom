<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\RtdnService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class RtdnController extends PluginController
{
    public function handle(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $raw = (string) $request->getContent();
        $payload = $request->json()?->all() ?: [];
        if (!is_array($payload) || $payload === []) {
            $payload = $request->all();
        }
        $result = RtdnService::make()->handle(is_array($payload) ? $payload : [], $raw);
        return MobileEnvelope::success([
            'accepted' => (bool) ($result['accepted'] ?? false),
        ]);
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }
}
