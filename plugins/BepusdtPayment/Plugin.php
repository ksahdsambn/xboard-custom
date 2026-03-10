<?php

namespace Plugin\BepusdtPayment;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const API_CREATE_ORDER = '/api/v1/order/create-order';
    private const API_CREATE_TRANSACTION = '/api/v1/order/create-transaction';
    private const DEFAULT_TIMEOUT_SECONDS = 600;
    private const MIN_ORDER_TIMEOUT_SECONDS = 180;
    private const MIN_TRANSACTION_TIMEOUT_SECONDS = 120;

    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            if ($this->getConfig('enabled', true)) {
                $methods['BEpusdt'] = [
                    'name' => $this->getConfig('display_name', 'BEpusdt'),
                    'icon' => $this->getConfig('icon', 'USDT'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin',
                ];
            }

            return $methods;
        });

        $this->listen('payment.notify.before', function ($payload): void {
            if (!is_array($payload) || count($payload) < 3) {
                return;
            }

            [$method, $uuid, $request] = $payload;
            if ($method !== 'BEpusdt') {
                return;
            }

            $payment = Payment::where('uuid', $uuid)
                ->where('payment', 'BEpusdt')
                ->first();

            if (!$payment || !$payment->enable) {
                $this->intercept(response('gate is not enable', 400));
            }

            $originalConfig = $this->getConfig();
            $this->setConfig($this->normalizePaymentConfig($payment));

            try {
                $verify = $this->notify(is_object($request) ? $request->input() : []);
                if (!$verify) {
                    HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                    $this->intercept(response('verify error', 422));
                }

                $this->intercept($verify);
            } catch (\App\Services\Plugin\InterceptResponseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::error($e);
                $this->intercept(response('fail', 500));
            } finally {
                $this->setConfig($originalConfig);
            }
        }, 5);
    }

    public function form(): array
    {
        return [
            'base_url' => [
                'label' => 'BEpusdt Base URL',
                'type' => 'string',
                'required' => true,
                'description' => 'BEpusdt service base url, for example https://pay.example.com',
            ],
            'api_token' => [
                'label' => 'API Token',
                'type' => 'string',
                'required' => true,
                'description' => 'API token from BEpusdt system settings.',
            ],
            'fiat' => [
                'label' => 'Fiat Currency',
                'type' => 'string',
                'required' => true,
                'default' => strtoupper((string) admin_setting('currency', 'CNY')),
                'description' => 'Fiat currency sent to BEpusdt. Supported values depend on BEpusdt, for example CNY or USD.',
            ],
            'trade_type' => [
                'label' => 'Trade Type',
                'type' => 'string',
                'default' => '',
                'description' => 'Optional single-network restriction such as usdt.trc20. When set, the plugin uses create-transaction mode.',
            ],
            'currencies' => [
                'label' => 'Currencies Limit',
                'type' => 'string',
                'default' => '',
                'description' => 'Optional currency whitelist or blacklist for create-order mode, for example USDT,USDC or -ETH,-BNB.',
            ],
            'payment_address' => [
                'label' => 'Payment Address',
                'type' => 'string',
                'default' => '',
                'description' => 'Optional fixed receiving address for create-transaction mode.',
            ],
            'order_name' => [
                'label' => 'Order Name',
                'type' => 'string',
                'default' => (string) admin_setting('app_name', 'Xboard') . ' Subscription Order',
                'description' => 'Order title displayed on the BEpusdt checkout page.',
            ],
            'timeout_seconds' => [
                'label' => 'Order Timeout (seconds)',
                'type' => 'string',
                'default' => (string) self::DEFAULT_TIMEOUT_SECONDS,
                'description' => 'Order timeout in seconds. Minimum 180 for create-order mode and 120 for create-transaction mode.',
            ],
            'rate' => [
                'label' => 'Rate Override',
                'type' => 'string',
                'default' => '',
                'description' => 'Optional BEpusdt rate override. Only applies in create-transaction mode.',
            ],
        ];
    }

    public function pay($order): array
    {
        $tradeNo = trim((string) ($order['trade_no'] ?? ''));
        $totalAmount = (int) ($order['total_amount'] ?? 0);
        $returnUrl = trim((string) ($order['return_url'] ?? ''));
        $notifyUrl = trim((string) ($order['notify_url'] ?? ''));

        if ($tradeNo === '' || $totalAmount <= 0) {
            throw new ApiException('Invalid BEpusdt order payload');
        }

        if ($returnUrl === '' || $notifyUrl === '') {
            throw new ApiException('Missing BEpusdt callback url');
        }

        $payload = [
            'order_id' => $tradeNo,
            'amount' => $this->toFiatAmount($totalAmount),
            'notify_url' => $notifyUrl,
            'redirect_url' => $returnUrl,
            'fiat' => $this->getFiat(),
            'name' => $this->getOrderName(),
            'timeout' => $this->resolveTimeoutSeconds(),
        ];

        $endpoint = self::API_CREATE_ORDER;
        $tradeType = $this->getTradeType();
        if ($tradeType !== null) {
            $endpoint = self::API_CREATE_TRANSACTION;
            $payload['trade_type'] = $tradeType;

            $paymentAddress = $this->getPaymentAddress();
            if ($paymentAddress !== null) {
                $payload['address'] = $paymentAddress;
            }

            $rate = $this->getRateOverride();
            if ($rate !== null) {
                $payload['rate'] = $rate;
            }
        } else {
            $currencies = $this->getCurrenciesLimit();
            if ($currencies !== null) {
                $payload['currencies'] = $currencies;
            }
        }

        $payload['signature'] = $this->signPayload($payload);

        $response = $this->postJson($endpoint, $payload);
        $paymentUrl = $response['data']['payment_url'] ?? null;
        if (!is_string($paymentUrl) || $paymentUrl === '') {
            throw new ApiException('BEpusdt payment url is missing');
        }

        return [
            'type' => 1,
            'data' => $paymentUrl,
        ];
    }

    public function notify($params)
    {
        $params = is_array($params) ? $params : [];

        try {
            $payload = $this->extractWebhookPayload($params);
            if ($payload === []) {
                return false;
            }

            if (!$this->verifySignature($payload)) {
                throw new ApiException('Invalid BEpusdt signature');
            }

            $verify = $this->resolveWebhookResult($payload);
            if (!$verify) {
                return false;
            }

            if (!is_array($verify)) {
                return $verify;
            }

            HookManager::call('payment.notify.verified', $verify);
            if (!$this->markOrderPaid($verify['trade_no'], $verify['callback_no'])) {
                return response('handle error', 400);
            }

            return $verify['custom_result'] ?? 'success';
        } catch (ApiException $e) {
            Log::warning('BEpusdt notify rejected', [
                'uuid' => $this->getConfig('uuid'),
                'message' => $e->getMessage(),
            ]);
            return response($e->getMessage(), 400);
        } catch (\Throwable $e) {
            Log::error($e);
            return response('fail', 500);
        }
    }

    private function resolveWebhookResult(array $payload): array|string|bool
    {
        $status = (int) ($payload['status'] ?? 0);

        return match ($status) {
            1, 3 => 'success',
            2 => $this->resolveSuccessfulPayment($payload),
            default => $this->handleUnexpectedStatus($payload),
        };
    }

    private function resolveSuccessfulPayment(array $payload): array|string|bool
    {
        $tradeNo = trim((string) ($payload['order_id'] ?? ''));
        if ($tradeNo === '') {
            return false;
        }

        $callbackNo = trim((string) ($payload['block_transaction_id'] ?? $payload['trade_id'] ?? ''));
        if ($callbackNo === '') {
            return false;
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            Log::warning('BEpusdt webhook ignored because order does not exist', [
                'trade_no' => $tradeNo,
                'trade_id' => $payload['trade_id'] ?? null,
            ]);
            return 'success';
        }

        if ($order->status !== Order::STATUS_PENDING) {
            return 'success';
        }

        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        $callbackAmount = $this->toAmountInCents($payload['amount'] ?? null);
        if ($callbackAmount === null || $callbackAmount !== $expectedAmount) {
            Log::warning('BEpusdt webhook amount mismatch', [
                'trade_no' => $tradeNo,
                'expected_amount' => $expectedAmount,
                'callback_amount' => $callbackAmount,
            ]);
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'custom_result' => 'success',
        ];
    }

    private function handleUnexpectedStatus(array $payload): string
    {
        Log::warning('BEpusdt webhook ignored because status is unexpected', [
            'trade_id' => $payload['trade_id'] ?? null,
            'order_id' => $payload['order_id'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);

        return 'success';
    }

    private function extractWebhookPayload(array $params): array
    {
        try {
            $payload = request()->json()->all();
            if (is_array($payload) && $payload !== []) {
                return $this->stripInternalKeys($payload);
            }
        } catch (\Throwable) {
        }

        $requestContent = $params['__request_content'] ?? null;
        if (is_string($requestContent) && $requestContent !== '') {
            $decoded = json_decode($requestContent, true);
            if (is_array($decoded) && $decoded !== []) {
                return $this->stripInternalKeys($decoded);
            }
        }

        $sanitizedParams = $this->stripInternalKeys($params);
        if ($sanitizedParams !== []) {
            return $sanitizedParams;
        }

        try {
            $requestContent = request()->getContent();
            if (is_string($requestContent) && $requestContent !== '') {
                $decoded = json_decode($requestContent, true);
                if (is_array($decoded) && $decoded !== []) {
                    return $this->stripInternalKeys($decoded);
                }
            }
        } catch (\Throwable) {
        }

        return [];
    }

    private function stripInternalKeys(array $payload): array
    {
        unset($payload['__request_content'], $payload['__request_headers']);

        return $payload;
    }

    private function verifySignature(array $payload): bool
    {
        $signature = strtolower(trim((string) ($payload['signature'] ?? '')));
        if ($signature === '') {
            return false;
        }

        return hash_equals($this->signPayload($payload), $signature);
    }

    private function signPayload(array $payload): string
    {
        unset($payload['signature']);

        $pairs = [];
        ksort($payload, SORT_STRING);
        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $pairs[] = $key . '=' . $this->stringifyValue($value);
        }

        return md5(implode('&', $pairs) . $this->getApiToken());
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string) $value;
        }

        return (string) $value;
    }

    private function postJson(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new ApiException('Unable to encode BEpusdt payload');
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->joinUrl($this->getBaseUrl(), $path),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($curl);
        if ($response === false) {
            $message = curl_error($curl) ?: 'BEpusdt request failed';
            curl_close($curl);
            throw new ApiException($message);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ApiException('Invalid BEpusdt response payload');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = trim((string) ($decoded['message'] ?? 'BEpusdt request failed'));
            throw new ApiException($message !== '' ? $message : 'BEpusdt request failed');
        }

        $status = (int) ($decoded['status_code'] ?? 0);
        if ($status !== 200) {
            $message = trim((string) ($decoded['message'] ?? 'BEpusdt request failed'));
            throw new ApiException($message !== '' ? $message : 'BEpusdt request failed');
        }

        return $decoded;
    }

    private function joinUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function getBaseUrl(): string
    {
        $baseUrl = trim((string) $this->getConfig('base_url'));
        if ($baseUrl === '') {
            throw new ApiException('BEpusdt base url is required');
        }

        return $baseUrl;
    }

    private function getApiToken(): string
    {
        $token = trim((string) $this->getConfig('api_token'));
        if ($token === '') {
            throw new ApiException('BEpusdt api token is required');
        }

        return $token;
    }

    private function getFiat(): string
    {
        $fiat = strtoupper(trim((string) $this->getConfig('fiat', admin_setting('currency', 'CNY'))));
        if ($fiat === '') {
            throw new ApiException('BEpusdt fiat is required');
        }

        return $fiat;
    }

    private function getOrderName(): string
    {
        $orderName = trim((string) $this->getConfig('order_name', (string) admin_setting('app_name', 'Xboard') . ' Subscription Order'));

        return $orderName !== '' ? $orderName : 'Xboard Subscription Order';
    }

    private function getTradeType(): ?string
    {
        $tradeType = trim((string) $this->getConfig('trade_type'));

        return $tradeType !== '' ? $tradeType : null;
    }

    private function getCurrenciesLimit(): ?string
    {
        $currencies = trim((string) $this->getConfig('currencies'));

        return $currencies !== '' ? $currencies : null;
    }

    private function getPaymentAddress(): ?string
    {
        $paymentAddress = trim((string) $this->getConfig('payment_address'));

        return $paymentAddress !== '' ? $paymentAddress : null;
    }

    private function getRateOverride(): ?string
    {
        $rate = trim((string) $this->getConfig('rate'));

        return $rate !== '' ? $rate : null;
    }

    private function resolveTimeoutSeconds(): int
    {
        $timeout = (int) ($this->getConfig('timeout_seconds') ?: self::DEFAULT_TIMEOUT_SECONDS);
        $minimum = $this->getTradeType() !== null
            ? self::MIN_TRANSACTION_TIMEOUT_SECONDS
            : self::MIN_ORDER_TIMEOUT_SECONDS;

        return max($minimum, $timeout);
    }

    private function toFiatAmount(int $amountInCents): float
    {
        return round($amountInCents / 100, 2);
    }

    private function toAmountInCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    private function markOrderPaid(string $tradeNo, string $callbackNo): bool
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            Log::warning('BEpusdt notify ignored because order does not exist during mark paid', [
                'trade_no' => $tradeNo,
                'callback_no' => $callbackNo,
            ]);
            return true;
        }

        if ($order->status !== Order::STATUS_PENDING) {
            return true;
        }

        $updated = Order::query()
            ->where('id', $order->id)
            ->where('status', Order::STATUS_PENDING)
            ->update([
                'status' => Order::STATUS_PROCESSING,
                'paid_at' => time(),
                'callback_no' => $callbackNo,
            ]);

        if (!$updated) {
            $currentStatus = Order::query()
                ->where('id', $order->id)
                ->value('status');

            return $currentStatus !== null && $currentStatus !== Order::STATUS_PENDING;
        }

        try {
            OrderHandleJob::dispatchSync($order->trade_no);
            HookManager::call('payment.notify.success', $order);
        } catch (\Throwable $e) {
            Log::error($e);
            return false;
        }

        return true;
    }

    private function normalizePaymentConfig(Payment $payment): array
    {
        $config = is_string($payment->config) ? json_decode($payment->config, true) : $payment->config;
        if (!is_array($config)) {
            $config = [];
        }

        $config['enable'] = $payment->enable;
        $config['id'] = $payment->id;
        $config['uuid'] = $payment->uuid;
        $config['notify_domain'] = $payment->notify_domain ?? '';

        return $config;
    }
}
