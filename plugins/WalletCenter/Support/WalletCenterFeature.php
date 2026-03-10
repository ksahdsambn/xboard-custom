<?php

namespace Plugin\WalletCenter\Support;

final class WalletCenterFeature
{
    public const PLUGIN_CODE = 'wallet_center';
    public const ROUTE_PREFIX = '/api/v1/wallet-center';

    public const CHECKIN = 'checkin';
    public const TOPUP = 'topup';
    public const AUTO_RENEW = 'auto_renew';

    public static function definitions(): array
    {
        return [
            self::CHECKIN => [
                'label' => '每日签到',
                'config_key' => 'checkin_enabled',
                'config_entries' => [
                    'checkin_enabled',
                    'checkin_reward_min',
                    'checkin_reward_max',
                ],
                'frontend_entries' => [
                    'status' => self::ROUTE_PREFIX . '/checkin/status',
                    'claim' => self::ROUTE_PREFIX . '/checkin/claim',
                    'history' => self::ROUTE_PREFIX . '/checkin/history',
                ],
                'record_entries' => [
                    'user' => self::ROUTE_PREFIX . '/checkin/history',
                    'admin' => self::ROUTE_PREFIX . '/admin/checkin/logs',
                ],
                'execution_entries' => [
                    'api' => self::ROUTE_PREFIX . '/checkin/claim',
                ],
                'tables' => [
                    'wallet_center_checkin_logs',
                ],
            ],
            self::TOPUP => [
                'label' => '余额充值',
                'config_key' => 'topup_enabled',
                'config_entries' => [
                    'topup_enabled',
                    'topup_min_amount',
                    'topup_max_amount',
                ],
                'frontend_entries' => [
                    'methods' => self::ROUTE_PREFIX . '/topup/methods',
                    'create' => self::ROUTE_PREFIX . '/topup/create',
                    'detail' => self::ROUTE_PREFIX . '/topup/detail',
                    'history' => self::ROUTE_PREFIX . '/topup/history',
                ],
                'record_entries' => [
                    'user' => self::ROUTE_PREFIX . '/topup/history',
                    'admin' => self::ROUTE_PREFIX . '/admin/topup/orders',
                ],
                'execution_entries' => [
                    'create' => self::ROUTE_PREFIX . '/topup/create',
                    'notify' => self::ROUTE_PREFIX . '/topup/notify/{method}/{uuid}',
                ],
                'tables' => [
                    'wallet_center_topup_orders',
                ],
            ],
            self::AUTO_RENEW => [
                'label' => '余额自动续费',
                'config_key' => 'auto_renew_enabled',
                'config_entries' => [
                    'auto_renew_enabled',
                    'auto_renew_window_hours',
                ],
                'frontend_entries' => [
                    'config' => self::ROUTE_PREFIX . '/auto-renew/config',
                    'history' => self::ROUTE_PREFIX . '/auto-renew/history',
                ],
                'record_entries' => [
                    'user' => self::ROUTE_PREFIX . '/auto-renew/history',
                    'admin' => self::ROUTE_PREFIX . '/admin/auto-renew/records',
                ],
                'execution_entries' => [
                    'api' => self::ROUTE_PREFIX . '/auto-renew/config',
                    'command' => 'wallet-center:auto-renew-scan',
                ],
                'tables' => [
                    'wallet_center_auto_renew_settings',
                    'wallet_center_auto_renew_records',
                ],
            ],
        ];
    }

    public static function all(): array
    {
        return array_keys(self::definitions());
    }

    public static function definition(string $feature): array
    {
        $definitions = self::definitions();

        if (!isset($definitions[$feature])) {
            throw new \InvalidArgumentException("Unknown WalletCenter feature [{$feature}]");
        }

        return $definitions[$feature];
    }

    public static function label(string $feature): string
    {
        return self::definition($feature)['label'];
    }

    public static function configKey(string $feature): string
    {
        return self::definition($feature)['config_key'];
    }
}
