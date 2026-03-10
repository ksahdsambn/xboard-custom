<?php

namespace Plugin\WalletCenter\Services;

use Plugin\WalletCenter\Support\WalletCenterFeature;

class WalletCenterManifestService
{
    public function __construct(
        protected WalletCenterConfigService $configService
    ) {
    }

    public function getOverview(): array
    {
        $features = [];
        foreach (WalletCenterFeature::all() as $feature) {
            $features[$feature] = $this->getFeatureBlueprint($feature);
        }

        return [
            'plugin_code' => WalletCenterFeature::PLUGIN_CODE,
            'phase' => 'stage-05-skeleton',
            'plugin_enabled' => $this->configService->isPluginEnabled(),
            'boundaries' => [
                'WalletCenter 只承载签到、充值、自动续费三类钱包业务，不实现新的支付网关。',
                'WalletCenter 使用独立数据表记录充值与自动续费，不复用核心 v2_order。',
                'WalletCenter 只读取已启用支付通道，不接管普通订阅支付回调。',
            ],
            'tables' => [
                'wallet_center_checkin_logs',
                'wallet_center_topup_orders',
                'wallet_center_auto_renew_settings',
                'wallet_center_auto_renew_records',
            ],
            'features' => $features,
        ];
    }

    public function getFeatureBlueprint(string $feature): array
    {
        $definition = WalletCenterFeature::definition($feature);

        return [
            'key' => $feature,
            'label' => $definition['label'],
            'enabled' => $this->configService->isFeatureEnabled($feature),
            'config_entries' => $this->configService->getFeatureConfigSnapshot($feature),
            'frontend_entries' => $definition['frontend_entries'],
            'record_entries' => $definition['record_entries'],
            'execution_entries' => $definition['execution_entries'],
            'tables' => $definition['tables'],
        ];
    }

    public function getFeatureLabel(string $feature): string
    {
        return WalletCenterFeature::label($feature);
    }
}
