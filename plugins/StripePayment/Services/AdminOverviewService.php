<?php

namespace Plugin\StripePayment\Services;

use App\Models\Payment;
use App\Models\Plugin as PluginModel;
use Plugin\StripePayment\Plugin;

class AdminOverviewService
{
    private const PLUGIN_CODE = 'stripe_payment';
    private const PAYMENT_METHOD = 'Stripe';

    public function getOverview(): array
    {
        $instances = Payment::query()
            ->where('payment', self::PAYMENT_METHOD)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $payment): array => $this->formatInstance($payment))
            ->values()
            ->all();

        $summary = [
            'total_instances' => count($instances),
            'enabled_instances' => count(array_filter($instances, fn (array $instance): bool => $instance['enabled'])),
            'ready_instances' => count(array_filter($instances, fn (array $instance): bool => $instance['status'] === 'ready')),
            'incomplete_instances' => count(array_filter($instances, fn (array $instance): bool => $instance['status'] === 'config_incomplete')),
        ];

        return [
            'phase' => 'stage-10-admin-config-records',
            'plugin_code' => self::PLUGIN_CODE,
            'plugin_enabled' => $this->isPluginEnabled(),
            'payment_method' => self::PAYMENT_METHOD,
            'fund_activity_type' => 'subscription_payment',
            'admin_endpoints' => [
                'overview' => '/api/v1/stripe-payment/admin/overview',
                'core_payment_list' => $this->buildAdminPath('payment/fetch'),
                'core_payment_config' => $this->buildAdminPath('payment/getPaymentForm'),
                'core_payment_save' => $this->buildAdminPath('payment/save'),
                'core_payment_toggle' => $this->buildAdminPath('payment/show'),
            ],
            'capabilities' => [
                'one_time_checkout' => true,
                'recurring_billing' => false,
                'saved_cards' => false,
                'automatic_off_session_charge' => false,
            ],
            'required_config' => $this->getConfigDefinition(),
            'summary' => $summary,
            'instances' => $instances,
        ];
    }

    protected function formatInstance(Payment $payment): array
    {
        $config = is_array($payment->config) ? $payment->config : [];
        $missingFields = $this->resolveMissingFields($config);
        $enabled = (bool) $payment->enable;

        return [
            'id' => $payment->id,
            'name' => $payment->name,
            'enabled' => $enabled,
            'status' => !$enabled
                ? 'disabled'
                : (empty($missingFields) ? 'ready' : 'config_incomplete'),
            'status_message' => !$enabled
                ? 'Payment instance is disabled.'
                : (empty($missingFields) ? 'Stripe instance is ready.' : 'Stripe instance is missing required configuration.'),
            'uuid' => $payment->uuid,
            'notify_url' => $this->buildNotifyUrl($payment),
            'notify_domain' => $payment->notify_domain,
            'sort' => $payment->sort,
            'handling_fee_fixed' => (int) ($payment->handling_fee_fixed ?? 0),
            'handling_fee_percent' => (float) ($payment->handling_fee_percent ?? 0),
            'runtime_mode' => $this->resolveRuntimeMode((string) ($config['secret_key'] ?? '')),
            'missing_fields' => $missingFields,
            'config_snapshot' => [
                'display_name' => $config['display_name'] ?? $payment->name,
                'icon' => $config['icon'] ?? 'Stripe',
                'currency' => strtoupper((string) ($config['currency'] ?? '')),
                'product_name' => $config['product_name'] ?? null,
                'session_expire_minutes' => (int) ($config['session_expire_minutes'] ?? 0),
                'webhook_tolerance_seconds' => (int) ($config['webhook_tolerance_seconds'] ?? 0),
                'secret_key' => $this->maskSecret((string) ($config['secret_key'] ?? '')),
                'webhook_secret' => $this->maskSecret((string) ($config['webhook_secret'] ?? '')),
            ],
        ];
    }

    protected function getConfigDefinition(): array
    {
        $plugin = new Plugin(self::PLUGIN_CODE);
        $fields = [];

        foreach ($plugin->form() as $key => $field) {
            $fields[$key] = [
                'label' => $field['label'] ?? $key,
                'type' => $field['type'] ?? 'string',
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        return $fields;
    }

    protected function resolveMissingFields(array $config): array
    {
        $requiredFields = array_filter(
            $this->getConfigDefinition(),
            fn (array $field): bool => $field['required']
        );

        $missing = [];
        foreach ($requiredFields as $key => $field) {
            $value = $config[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $missing[] = [
                    'key' => $key,
                    'label' => $field['label'],
                ];
            }
        }

        return $missing;
    }

    protected function resolveRuntimeMode(string $secretKey): string
    {
        $normalized = trim($secretKey);

        return match (true) {
            str_starts_with($normalized, 'sk_live_') => 'live',
            str_starts_with($normalized, 'sk_test_') => 'test',
            default => 'unknown',
        };
    }

    protected function buildNotifyUrl(Payment $payment): string
    {
        $notifyUrl = url('/api/v1/guest/payment/notify/' . self::PAYMENT_METHOD . '/' . $payment->uuid);
        if (!empty($payment->notify_domain)) {
            $parsed = parse_url($notifyUrl);
            $notifyUrl = rtrim((string) $payment->notify_domain, '/') . ($parsed['path'] ?? '');
        }

        return $notifyUrl;
    }

    protected function buildAdminPath(string $suffix): string
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', (string) config('app.key')))
        );

        return '/api/v2/' . trim((string) $securePath, '/') . '/' . ltrim($suffix, '/');
    }

    protected function isPluginEnabled(): bool
    {
        return (bool) optional(
            PluginModel::query()->where('code', self::PLUGIN_CODE)->first()
        )->is_enabled;
    }

    protected function maskSecret(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) <= 8) {
            return str_repeat('*', strlen($normalized));
        }

        return substr($normalized, 0, 4) . str_repeat('*', max(strlen($normalized) - 8, 4)) . substr($normalized, -4);
    }
}
