<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Plugin\MobileApp\Services\LegalDocumentService;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class LegalController extends PluginController
{
    public function privacy(): JsonResponse
    {
        return $this->ok((new LegalDocumentService())->privacy());
    }

    public function terms(): JsonResponse
    {
        return $this->ok((new LegalDocumentService())->terms());
    }

    public function accountDeletion(): JsonResponse
    {
        return $this->ok((new LegalDocumentService())->accountDeletion());
    }

    public function support(): JsonResponse
    {
        return $this->ok((new LegalDocumentService())->support());
    }

    private function ok(array $data): JsonResponse
    {
        if (!PluginStatus::isEnabled()) {
            return MobileEnvelope::fail('SERVICE_MAINTENANCE', 503);
        }
        return MobileEnvelope::success($data);
    }
}
