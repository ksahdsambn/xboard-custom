<?php

namespace Plugin\WalletCenter\Services;

use Plugin\WalletCenter\Support\WalletCenterFeature;

class WalletCenterAdminOverviewService
{
    public function __construct(
        protected WalletCenterConfigService $configService,
        protected WalletCenterManifestService $manifestService,
        protected WalletCenterPaymentChannelService $paymentChannelService,
        protected CheckinService $checkinService,
        protected TopupService $topupService,
        protected AutoRenewService $autoRenewService
    ) {
    }

    public function getOverview(): array
    {
        $overview = $this->manifestService->getOverview();
        $overview['phase'] = 'stage-10-admin-config-records';
        $overview['config_sections'] = $this->configService->getGroupedConfigSnapshots();
        $overview['feature_states'] = $this->configService->getFeatureStates();
        $overview['available_payment_channels'] = $this->paymentChannelService->listEnabledChannels();
        $overview['status_summary'] = [
            WalletCenterFeature::CHECKIN => $this->checkinService->getAdminSummary(),
            WalletCenterFeature::TOPUP => $this->topupService->getAdminSummary(),
            WalletCenterFeature::AUTO_RENEW => $this->autoRenewService->getAdminSummary(),
        ];
        $overview['fund_activity_streams'] = $this->getFundActivityStreams();

        return $overview;
    }

    protected function getFundActivityStreams(): array
    {
        return [
            [
                'type' => 'subscription_payment',
                'label' => 'Standard subscription payment',
                'description' => 'Core subscription order payments handled by standard payment instances.',
                'admin_entries' => [
                    'orders' => $this->buildAdminPath('order/fetch'),
                    'payments' => $this->buildAdminPath('payment/fetch'),
                    'stripe_status' => '/api/v1/stripe-payment/admin/overview',
                    'bepusdt_status' => '/api/v1/bepusdt-payment/admin/overview',
                ],
                'record_tables' => [
                    'v2_order',
                    'v2_payment',
                ],
            ],
            [
                'type' => 'balance_topup',
                'label' => 'Balance topup',
                'description' => 'WalletCenter balance recharge orders and callback results.',
                'admin_entries' => [
                    'orders' => WalletCenterFeature::ROUTE_PREFIX . '/admin/topup/orders',
                ],
                'record_tables' => [
                    'wallet_center_topup_orders',
                ],
            ],
            [
                'type' => 'auto_renew_execution',
                'label' => 'Balance auto renew execution',
                'description' => 'WalletCenter automatic renewal attempts and outcomes.',
                'admin_entries' => [
                    'records' => WalletCenterFeature::ROUTE_PREFIX . '/admin/auto-renew/records',
                ],
                'record_tables' => [
                    'wallet_center_auto_renew_records',
                ],
            ],
        ];
    }

    protected function buildAdminPath(string $suffix): string
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', (string) config('app.key')))
        );

        return '/api/v2/' . trim((string) $securePath, '/') . '/' . ltrim($suffix, '/');
    }
}
