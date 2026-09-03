<?php

namespace Plugin\WalletCenter\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\WalletCenter\Services\WalletCenterConfigService;
use Plugin\WalletCenter\Services\WalletCenterManifestService;

abstract class BaseController extends PluginController
{
    public function __construct(
        protected WalletCenterConfigService $configService,
        protected WalletCenterManifestService $manifestService
    ) {
    }

    protected function requireFeature(string $feature): ?JsonResponse
    {
        if ($error = $this->beforePluginAction()) {
            return $this->fail($error);
        }

        if ($this->configService->isFeatureEnabled($feature)) {
            return null;
        }

        return $this->fail([403, sprintf('%s功能当前未启用。', $this->manifestService->getFeatureLabel($feature))]);
    }

    protected function resolvePage(Request $request): int
    {
        $page = (int) $request->query('page', 1);

        return $page > 0 ? $page : 1;
    }

    protected function featurePayload(string $feature, array $extra = [], string $phase = 'wallet-center'): array
    {
        return array_merge([
            'phase' => $phase,
            'feature' => $this->manifestService->getFeatureBlueprint($feature),
        ], $extra);
    }

    protected function skeletonPayload(string $feature, array $extra = []): array
    {
        return $this->featurePayload($feature, $extra);
    }

    protected function resolveLimit(Request $request, int $default = 20, int $max = 100): int
    {
        $limit = (int) $request->query('limit', $default);

        if ($limit <= 0) {
            return $default;
        }

        return min($limit, $max);
    }
}
