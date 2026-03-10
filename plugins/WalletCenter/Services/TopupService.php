<?php

namespace Plugin\WalletCenter\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\WalletCenter\Models\TopupOrder;

class TopupService
{
    public function __construct(
        protected WalletCenterConfigService $configService,
        protected WalletCenterPaymentChannelService $paymentChannelService,
        protected TopupGatewayService $gatewayService,
        protected UserService $userService
    ) {
    }

    public function getAmountRangeSnapshot(): array
    {
        $config = $this->configService->getConfig();
        $min = $this->toInteger($config['topup_min_amount'] ?? 0);
        $max = $this->toInteger($config['topup_max_amount'] ?? 0);

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [
            'min' => $min,
            'max' => $max,
            'valid' => $min > 0 && $max > 0 && $max >= $min,
        ];
    }

    public function listAvailableChannels(): array
    {
        return array_values(array_filter(
            $this->paymentChannelService->listEnabledChannels(),
            fn (array $channel): bool => $this->gatewayService->supportsMethod($channel['payment'] ?? null)
        ));
    }

    public function create(User $user, array $payload, array $requestMeta = []): array
    {
        $amount = $this->toInteger($payload['amount'] ?? null);
        $paymentId = (int) ($payload['payment_id'] ?? 0);
        if ($amount <= 0 || $paymentId <= 0) {
            throw new ApiException('WalletCenter topup parameters are invalid');
        }

        $amountRange = $this->getValidatedAmountRange();
        if ($amount < $amountRange['min'] || $amount > $amountRange['max']) {
            throw new ApiException('WalletCenter topup amount is out of range');
        }

        $payment = $this->paymentChannelService->findEnabledPaymentById($paymentId);
        if (!$payment || !$this->gatewayService->supportsMethod($payment->payment)) {
            throw new ApiException('Payment method is not available');
        }

        $channelSnapshot = $this->buildChannelSnapshot($paymentId);
        $tradeNo = Helper::generateOrderNo();
        $handlingAmount = $this->calculateHandlingAmount(
            $amount,
            (float) ($payment->handling_fee_percent ?? 0),
            (int) ($payment->handling_fee_fixed ?? 0)
        );

        $order = TopupOrder::query()->create([
            'trade_no' => $tradeNo,
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'payment_method' => $payment->payment,
            'payment_plugin_code' => $channelSnapshot['plugin_code'] ?? null,
            'amount' => $amount,
            'handling_amount' => $handlingAmount,
            'status' => TopupOrder::STATUS_PENDING,
            'channel_snapshot' => $channelSnapshot,
            'extra' => [
                'source' => 'wallet_center_topup',
                'request_ip' => $requestMeta['request_ip'] ?? null,
                'user_agent' => $requestMeta['user_agent'] ?? null,
                'notify_url' => $this->gatewayService->buildNotifyUrl($payment),
            ],
        ]);

        try {
            $returnUrl = $this->gatewayService->buildReturnUrl($order);
            $this->updateExtra($order, [
                'return_url' => $returnUrl,
            ]);

            $paymentResult = $this->gatewayService->createPayment($payment, $order, $returnUrl);
            $this->updateExtra($order, [
                'payment_result' => [
                    'type' => $paymentResult['type'] ?? null,
                    'data' => $paymentResult['data'] ?? null,
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->updateExtra($order, [
                'payment_create_failed' => true,
                'payment_create_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return [
            'order' => $order->fresh(),
            'payment_result' => $paymentResult,
            'amount_range' => $amountRange,
            'payment_channels' => $this->listAvailableChannels(),
        ];
    }

    public function getOrderForUser(User $user, string $tradeNo): ?TopupOrder
    {
        return TopupOrder::query()
            ->where('user_id', $user->id)
            ->where('trade_no', $tradeNo)
            ->first();
    }

    public function getHistoryForUser(User $user, int $limit = 20): Collection
    {
        return TopupOrder::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function getAdminOrders(int $limit = 20): Collection
    {
        return TopupOrder::query()
            ->with('user:id,email', 'payment:id,name,payment')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function getAdminSummary(): array
    {
        $statusCounts = [
            'pending' => TopupOrder::query()->where('status', TopupOrder::STATUS_PENDING)->count(),
            'processing' => TopupOrder::query()->where('status', TopupOrder::STATUS_PROCESSING)->count(),
            'paid' => TopupOrder::query()->where('status', TopupOrder::STATUS_PAID)->count(),
            'cancelled' => TopupOrder::query()->where('status', TopupOrder::STATUS_CANCELLED)->count(),
            'expired' => TopupOrder::query()->where('status', TopupOrder::STATUS_EXPIRED)->count(),
        ];

        $latestOrder = TopupOrder::query()
            ->with('user:id,email', 'payment:id,name,payment')
            ->orderByDesc('id')
            ->first();

        $latestFailure = TopupOrder::query()
            ->where(function ($query): void {
                $query->whereIn('status', [
                    TopupOrder::STATUS_CANCELLED,
                    TopupOrder::STATUS_EXPIRED,
                ])->orWhereNotNull('extra->payment_create_error');
            })
            ->with('user:id,email', 'payment:id,name,payment')
            ->orderByDesc('id')
            ->first();

        return [
            'enabled' => $this->configService->isFeatureEnabled('topup'),
            'record_count' => array_sum($statusCounts),
            'status_counts' => $statusCounts,
            'amount_range' => $this->getAmountRangeSnapshot(),
            'latest_order' => $latestOrder,
            'latest_failure' => $latestFailure,
        ];
    }

    public function processNotification(string $method, string $uuid, Request $request): array
    {
        $payment = $this->paymentChannelService->findEnabledPaymentByMethodAndUuid($method, $uuid);
        if (!$payment || !$this->gatewayService->supportsMethod($payment->payment)) {
            return [
                'response' => response('gate is not enable', 400),
                'result' => null,
            ];
        }

        $result = $this->gatewayService->handleNotify($payment, $request);
        $tradeNo = $result['trade_no'] ?? null;
        $callbackNo = $result['callback_no'] ?? null;
        $status = $result['status'] ?? null;
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];

        if (is_string($tradeNo) && $tradeNo !== '' && $status === TopupOrder::STATUS_PAID) {
            if (!$this->markPaid($tradeNo, (string) $callbackNo, $meta)) {
                return [
                    'response' => response('handle error', 400),
                    'result' => $result,
                ];
            }
        } elseif (is_string($tradeNo) && $tradeNo !== '' && $status !== null) {
            $this->markStatus($tradeNo, (int) $status, $callbackNo, $meta);
        }

        return [
            'response' => $result['response'],
            'result' => $result,
        ];
    }

    protected function markPaid(string $tradeNo, string $callbackNo, array $meta = []): bool
    {
        try {
            return DB::transaction(function () use ($tradeNo, $callbackNo, $meta): bool {
                $order = TopupOrder::query()
                    ->where('trade_no', $tradeNo)
                    ->lockForUpdate()
                    ->first();
                if (!$order) {
                    Log::warning('WalletCenter topup paid callback ignored because order does not exist', [
                        'trade_no' => $tradeNo,
                        'callback_no' => $callbackNo,
                    ]);
                    return true;
                }

                if ((int) $order->status === TopupOrder::STATUS_PAID) {
                    return true;
                }

                if (in_array((int) $order->status, [TopupOrder::STATUS_CANCELLED, TopupOrder::STATUS_EXPIRED], true)) {
                    $this->updateExtra($order, [
                        'late_paid_callback' => [
                            'callback_no' => $callbackNo,
                            'received_at' => now()->toIso8601String(),
                            'meta' => $meta,
                        ],
                    ]);

                    return true;
                }

                $user = User::query()
                    ->whereKey($order->user_id)
                    ->lockForUpdate()
                    ->first();
                if (!$user) {
                    throw new \RuntimeException('WalletCenter topup user does not exist');
                }

                $balanceBefore = (int) ($user->balance ?? 0);

                $order->status = TopupOrder::STATUS_PROCESSING;
                $order->callback_no = $callbackNo;
                $order->save();

                if (!$this->userService->addBalance($user->id, (int) $order->amount)) {
                    throw new \RuntimeException('WalletCenter topup balance credit failed');
                }

                $user->refresh();

                $order->status = TopupOrder::STATUS_PAID;
                $order->paid_at = now();
                $order->callback_no = $callbackNo;
                $order->extra = $this->mergeExtra($order->extra, [
                    'balance_before' => $balanceBefore,
                    'balance_after' => (int) ($user->balance ?? 0),
                    'last_notify_status' => 'paid',
                    'last_notify_at' => now()->toIso8601String(),
                    'last_notify_meta' => $meta,
                ]);

                return (bool) $order->save();
            }, 3);
        } catch (\Throwable $exception) {
            Log::error($exception);

            return false;
        }
    }

    protected function markStatus(string $tradeNo, int $status, ?string $callbackNo = null, array $meta = []): void
    {
        DB::transaction(function () use ($tradeNo, $status, $callbackNo, $meta): void {
            $order = TopupOrder::query()
                ->where('trade_no', $tradeNo)
                ->lockForUpdate()
                ->first();
            if (!$order) {
                return;
            }

            $currentStatus = (int) $order->status;
            if (in_array($currentStatus, [
                TopupOrder::STATUS_PROCESSING,
                TopupOrder::STATUS_PAID,
                TopupOrder::STATUS_CANCELLED,
                TopupOrder::STATUS_EXPIRED,
            ], true)) {
                $order->extra = $this->mergeExtra($order->extra, [
                    'ignored_status_callback' => [
                        'current_status' => TopupOrder::statusLabel($currentStatus),
                        'incoming_status' => TopupOrder::statusLabel($status),
                        'callback_no' => is_string($callbackNo) && $callbackNo !== '' ? $callbackNo : null,
                        'received_at' => now()->toIso8601String(),
                        'meta' => $meta,
                    ],
                ]);
                $order->save();

                return;
            }

            if ($status !== TopupOrder::STATUS_PENDING) {
                $order->status = $status;
            }

            if (is_string($callbackNo) && $callbackNo !== '') {
                $order->callback_no = $callbackNo;
            }

            $order->extra = $this->mergeExtra($order->extra, [
                'last_notify_status' => TopupOrder::statusLabel($status),
                'last_notify_at' => now()->toIso8601String(),
                'last_notify_meta' => $meta,
            ]);

            $order->save();
        }, 3);
    }

    protected function buildChannelSnapshot(int $paymentId): array
    {
        $channel = $this->paymentChannelService->getChannelSnapshotByPaymentId($paymentId);

        return $channel ?? [
            'payment_id' => $paymentId,
        ];
    }

    protected function calculateHandlingAmount(int $amount, float $handlingPercent, int $handlingFixed): int
    {
        if ($handlingPercent <= 0 && $handlingFixed <= 0) {
            return 0;
        }

        return (int) round(($amount * ($handlingPercent / 100)) + $handlingFixed);
    }

    protected function getValidatedAmountRange(): array
    {
        $range = $this->getAmountRangeSnapshot();
        if (!$range['valid']) {
            throw new ApiException('WalletCenter topup amount configuration is invalid');
        }

        return $range;
    }

    protected function updateExtra(TopupOrder $order, array $extra): void
    {
        $order->extra = $this->mergeExtra($order->extra, $extra);
        $order->save();
    }

    protected function mergeExtra(mixed $original, array $extra): array
    {
        $base = is_array($original) ? $original : [];

        return array_replace_recursive($base, array_filter(
            $extra,
            static fn ($value) => $value !== null && $value !== ''
        ));
    }

    protected function toInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        return 0;
    }
}
