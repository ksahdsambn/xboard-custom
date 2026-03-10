<?php

namespace Plugin\WalletCenter\Controllers;

use Illuminate\Http\Request;
use Plugin\WalletCenter\Services\AutoRenewService;
use Plugin\WalletCenter\Services\CheckinService;
use Plugin\WalletCenter\Services\TopupService;
use Plugin\WalletCenter\Services\WalletCenterAdminOverviewService;
use Plugin\WalletCenter\Services\WalletCenterConfigService;
use Plugin\WalletCenter\Services\WalletCenterManifestService;
use Plugin\WalletCenter\Support\WalletCenterFeature;

class AdminController extends BaseController
{
    public function __construct(
        WalletCenterConfigService $configService,
        WalletCenterManifestService $manifestService,
        protected WalletCenterAdminOverviewService $adminOverviewService,
        protected AutoRenewService $autoRenewService,
        protected CheckinService $checkinService,
        protected TopupService $topupService
    ) {
        parent::__construct($configService, $manifestService);
    }

    public function overview()
    {
        return $this->success($this->adminOverviewService->getOverview());
    }

    public function config()
    {
        return $this->success([
            'phase' => 'stage-10-admin-config-records',
            'plugin_enabled' => $this->configService->isPluginEnabled(),
            'feature_states' => $this->configService->getFeatureStates(),
            'config_sections' => $this->configService->getGroupedConfigSnapshots(),
        ]);
    }

    public function updateConfig(Request $request)
    {
        $payload = $request->validate([
            'config' => 'required|array',
        ]);

        $this->configService->updateConfig($payload['config']);

        return $this->success([
            'phase' => 'stage-10-admin-config-records',
            'plugin_enabled' => $this->configService->isPluginEnabled(),
            'feature_states' => $this->configService->getFeatureStates(),
            'config_sections' => $this->configService->getGroupedConfigSnapshots(),
        ]);
    }

    public function checkinLogs(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::CHECKIN)) {
            return $response;
        }

        $records = $this->checkinService->getAdminHistory($this->resolveLimit($request));

        return $this->success($this->featurePayload(WalletCenterFeature::CHECKIN, [
            'records' => $records,
            'count' => $records->count(),
            'summary' => $this->checkinService->getAdminSummary(),
            'reward_range' => $this->checkinService->getRewardRangeSnapshot(),
            'server_date' => now()->toDateString(),
        ], 'stage-06-checkin'));
    }

    public function topupOrders(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::TOPUP)) {
            return $response;
        }

        $records = $this->topupService->getAdminOrders($this->resolveLimit($request));

        return $this->success($this->featurePayload(WalletCenterFeature::TOPUP, [
            'records' => $records,
            'count' => $records->count(),
            'summary' => $this->topupService->getAdminSummary(),
            'amount_range' => $this->topupService->getAmountRangeSnapshot(),
            'payment_channels' => $this->topupService->listAvailableChannels(),
        ], 'stage-07-topup'));
    }

    public function autoRenewRecords(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::AUTO_RENEW)) {
            return $response;
        }

        $records = $this->autoRenewService->getAdminRecords($this->resolveLimit($request));

        return $this->success($this->featurePayload(WalletCenterFeature::AUTO_RENEW, [
            'records' => $records,
            'count' => $records->count(),
            'summary' => $this->autoRenewService->getAdminSummary(),
            'renew_window_hours' => $this->autoRenewService->getWindowHours(),
        ], 'stage-08-auto-renew'));
    }
}
