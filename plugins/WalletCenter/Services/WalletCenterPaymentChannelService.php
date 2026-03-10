<?php

namespace Plugin\WalletCenter\Services;

use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\Plugin\PluginManager;

class WalletCenterPaymentChannelService
{
    public function __construct(
        protected PluginManager $pluginManager
    ) {
    }

    public function listEnabledChannels(): array
    {
        $this->pluginManager->initializeEnabledPlugins();

        $availableMethods = $this->getAvailableMethodMap();
        if (empty($availableMethods)) {
            return [];
        }

        return Payment::query()
            ->where('enable', true)
            ->whereIn('payment', array_keys($availableMethods))
            ->orderBy('sort', 'ASC')
            ->get()
            ->map(function (Payment $payment) use ($availableMethods): array {
                $method = $availableMethods[$payment->payment] ?? [];

                return [
                    'id' => $payment->id,
                    'name' => $payment->name,
                    'payment' => $payment->payment,
                    'icon' => $payment->icon,
                    'handling_fee_fixed' => $payment->handling_fee_fixed,
                    'handling_fee_percent' => $payment->handling_fee_percent,
                    'plugin_code' => $method['plugin_code'] ?? null,
                    'type' => $method['type'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    public function findEnabledPaymentById(int $paymentId): ?Payment
    {
        if ($paymentId <= 0) {
            return null;
        }

        return Payment::query()
            ->where('id', $paymentId)
            ->where('enable', true)
            ->first();
    }

    public function findEnabledPaymentByMethodAndUuid(string $method, string $uuid): ?Payment
    {
        if ($method === '' || $uuid === '') {
            return null;
        }

        return Payment::query()
            ->where('payment', $method)
            ->where('uuid', $uuid)
            ->where('enable', true)
            ->first();
    }

    public function getChannelSnapshotByPaymentId(int $paymentId): ?array
    {
        if ($paymentId <= 0) {
            return null;
        }

        foreach ($this->listEnabledChannels() as $channel) {
            if ((int) ($channel['id'] ?? 0) === $paymentId) {
                return $channel;
            }
        }

        return null;
    }

    protected function getAvailableMethodMap(): array
    {
        return (new PaymentService('temp'))->getAvailablePaymentMethods();
    }
}
