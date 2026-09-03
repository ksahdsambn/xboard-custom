<?php

namespace Plugin\WalletCenter;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Plugin\WalletCenter\Services\AutoRenewService;
use Plugin\WalletCenter\Services\CheckinService;
use Plugin\WalletCenter\Services\TopupService;
use Plugin\WalletCenter\Services\WalletCenterConfigService;
use Plugin\WalletCenter\Support\WalletCenterFeature;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('guest_comm_config', function ($config) {
            return $this->appendFrontendConfig(is_array($config) ? $config : []);
        });

        $this->filter('user_comm_config', function ($config) {
            return $this->appendFrontendConfig(is_array($config) ? $config : []);
        });

        $this->filter('admin.user.transform', function ($user, $model = null) {
            if (!is_array($user)) {
                return $user;
            }

            $user['wallet_center'] = $this->buildUserAdminSummary($model ?? null, (int) ($user['id'] ?? 0));

            return $user;
        });

        $this->filter('admin.user.detail', function ($user, $request = null) {
            if (!is_object($user) || !isset($user->id)) {
                return $user;
            }

            $user->setAttribute('wallet_center', $this->buildUserAdminSummary($user, (int) $user->id));

            return $user;
        });

        $this->listen('payment.notify.before', function ($payload): void {
            if (!is_array($payload) || count($payload) < 3) {
                return;
            }

            [$method, $uuid, $request] = $payload;
            $tradeNo = $this->peekIncomingTradeNo($request);
            if ($tradeNo === null) {
                return;
            }

            $topup = \Plugin\WalletCenter\Models\TopupOrder::query()
                ->where('trade_no', $tradeNo)
                ->first();
            if (!$topup) {
                return;
            }

            try {
                $result = app(TopupService::class)->processNotification((string) $method, (string) $uuid, $request);
                $this->intercept($result['response'] ?? 'success');
            } catch (\App\Services\Plugin\InterceptResponseException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                Log::error($exception);
                $this->intercept(response('fail', 500));
            }
        }, 1);
    }

    public function schedule(Schedule $schedule): void
    {
        $configService = app(WalletCenterConfigService::class);

        if ((bool) $this->getConfig('auto_renew_enabled', false)) {
            $limit = max(1, (int) ($this->getConfig('auto_renew_scan_limit', AutoRenewService::DEFAULT_SCAN_LIMIT) ?: AutoRenewService::DEFAULT_SCAN_LIMIT));
            $schedule->command(
                sprintf('wallet-center:auto-renew-scan --limit=%d --due-only', $limit)
            )
                ->name('plugin:wallet-center:auto-renew-scan')
                ->everyMinute()
                ->onOneServer()
                ->withoutOverlapping(AutoRenewService::SCHEDULE_OVERLAP_LOCK_MINUTES);
        }

        if ($configService->isPluginEnabled()) {
            $expireMinutes = max(30, (int) ($this->getConfig('topup_expire_minutes', 120) ?: 120));
            $schedule->command('wallet-center:expire-pending-topup --minutes=' . $expireMinutes)
                ->name('plugin:wallet-center:expire-pending-topup')
                ->hourly()
                ->onOneServer()
                ->withoutOverlapping(30);
        }
    }

    protected function peekIncomingTradeNo(mixed $request): ?string
    {
        $candidates = [];

        try {
            if (is_object($request) && method_exists($request, 'json')) {
                $json = $request->json()->all();
                if (is_array($json) && $json !== []) {
                    $candidates[] = $json['data']['object']['metadata']['trade_no'] ?? null;
                    $candidates[] = $json['data']['object']['client_reference_id'] ?? null;
                    $candidates[] = $json['order_id'] ?? null;
                    $candidates[] = $json['out_trade_no'] ?? null;
                }
            }
            if (is_object($request) && method_exists($request, 'getContent')) {
                $raw = $request->getContent();
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $candidates[] = $decoded['data']['object']['metadata']['trade_no'] ?? null;
                        $candidates[] = $decoded['data']['object']['client_reference_id'] ?? null;
                        $candidates[] = $decoded['order_id'] ?? null;
                        $candidates[] = $decoded['out_trade_no'] ?? null;
                    }
                }
            }
            if (is_object($request) && method_exists($request, 'input')) {
                $candidates[] = $request->input('order_id');
                $candidates[] = $request->input('out_trade_no');
                $candidates[] = $request->input('trade_no');
            }
        } catch (\Throwable) {
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    protected function appendFrontendConfig(array $config): array
    {
        $configService = app(WalletCenterConfigService::class);
        $states = $configService->getFeatureStates();

        $config['wallet_center'] = [
            'enabled' => $configService->isPluginEnabled(),
            'checkin_enabled' => (bool) ($states[WalletCenterFeature::CHECKIN] ?? false),
            'topup_enabled' => (bool) ($states[WalletCenterFeature::TOPUP] ?? false),
            'auto_renew_enabled' => (bool) ($states[WalletCenterFeature::AUTO_RENEW] ?? false),
            'wallet_hash' => '#/dashboard?xc_wallet=1',
            'display_name' => (string) ($configService->getConfig()['display_name'] ?? 'WalletCenter'),
        ];

        return $config;
    }

    protected function buildUserAdminSummary(mixed $user, int $userId): array
    {
        if ($userId <= 0) {
            return [
                'enabled' => false,
            ];
        }

        try {
            $configService = app(WalletCenterConfigService::class);
            $checkin = app(CheckinService::class);
            $topup = app(TopupService::class);
            $renew = app(AutoRenewService::class);
            $model = is_object($user) ? $user : \App\Models\User::query()->find($userId);

            return [
                'plugin_enabled' => $configService->isPluginEnabled(),
                'feature_states' => $configService->getFeatureStates(),
                'checkin' => $model ? $checkin->getStatusSnapshot($model) : null,
                'topup_latest' => $model ? $topup->getHistoryForUser($model, 5) : [],
                'auto_renew' => $model ? $renew->getConfigSnapshot($model) : null,
                'admin_records' => [
                    'checkin' => WalletCenterFeature::ROUTE_PREFIX . '/admin/checkin/logs',
                    'topup' => WalletCenterFeature::ROUTE_PREFIX . '/admin/topup/orders',
                    'auto_renew' => WalletCenterFeature::ROUTE_PREFIX . '/admin/auto-renew/records',
                ],
            ];
        } catch (\Throwable) {
            return [
                'plugin_enabled' => false,
            ];
        }
    }
}
