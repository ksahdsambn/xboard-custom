<?php

namespace Plugin\StripePayment;

use App\Jobs\OrderHandleJob;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const API_BASE_URL = 'https://api.stripe.com/v1';
    private const DEFAULT_SESSION_EXPIRE_MINUTES = 120;
    private const DEFAULT_WEBHOOK_TOLERANCE_SECONDS = 300;

    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            if ($this->getConfig('enabled', true)) {
                $methods['Stripe'] = [
                    'name' => $this->getConfig('display_name', 'Stripe'),
                    'icon' => $this->getConfig('icon', 'Stripe'),
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
            if ($method !== 'Stripe') {
                return;
            }

            $payment = Payment::where('uuid', $uuid)
                ->where('payment', 'Stripe')
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
            'secret_key' => [
                'label' => 'Secret Key',
                'type' => 'string',
                'required' => true,
                'description' => 'Stripe 后台 API 密钥，使用 sk_test_ 或 sk_live_ 开头的密钥。',
            ],
            'webhook_secret' => [
                'label' => 'Webhook Secret',
                'type' => 'string',
                'required' => true,
                'description' => 'Stripe webhook 端点签名密钥，使用 whsec_ 开头的密钥。',
            ],
            'currency' => [
                'label' => '货币代码',
                'type' => 'string',
                'required' => true,
                'default' => strtoupper((string) admin_setting('currency', 'USD')),
                'description' => 'Stripe 使用的三位货币代码，例如 USD、EUR。请与站点套餐定价币种保持一致。',
            ],
            'product_name' => [
                'label' => '订单标题',
                'type' => 'string',
                'default' => (string) admin_setting('app_name', 'Xboard') . ' 订阅订单',
                'description' => 'Stripe Checkout 页面展示的商品标题。',
            ],
            'session_expire_minutes' => [
                'label' => '会话超时（分钟）',
                'type' => 'string',
                'default' => (string) self::DEFAULT_SESSION_EXPIRE_MINUTES,
                'description' => 'Checkout 会话有效期，Stripe 允许 30 到 1440 分钟。',
            ],
            'webhook_tolerance_seconds' => [
                'label' => 'Webhook 时间窗（秒）',
                'type' => 'string',
                'default' => (string) self::DEFAULT_WEBHOOK_TOLERANCE_SECONDS,
                'description' => '校验 Stripe-Signature 时间戳时允许的偏差秒数，默认 300。',
            ],
        ];
    }

    public function pay($order): array
    {
        $tradeNo = (string) ($order['trade_no'] ?? '');
        $totalAmount = (int) ($order['total_amount'] ?? 0);
        $returnUrl = (string) ($order['return_url'] ?? '');
        $notifyUrl = (string) ($order['notify_url'] ?? '');
        $userId = (int) ($order['user_id'] ?? 0);

        if ($tradeNo === '' || $totalAmount <= 0) {
            throw new ApiException('Invalid Stripe order payload');
        }

        if ($returnUrl === '' || $notifyUrl === '') {
            throw new ApiException('Missing Stripe callback url');
        }

        $session = $this->createCheckoutSession([
            'trade_no' => $tradeNo,
            'total_amount' => $totalAmount,
            'user_id' => $userId,
            'return_url' => $returnUrl,
            'notify_url' => $notifyUrl,
        ]);

        $checkoutUrl = $session['url'] ?? null;
        if (!is_string($checkoutUrl) || $checkoutUrl === '') {
            throw new ApiException('Stripe checkout session url is missing');
        }

        return [
            'type' => 1,
            'data' => $checkoutUrl,
        ];
    }

    public function notify($params)
    {
        $params = is_array($params) ? $params : [];

        try {
            $payload = $this->getWebhookPayload($params);
            $signature = $this->getRequestHeader('Stripe-Signature', $params);

            if ($payload === '' || $signature === null || $signature === '') {
                return false;
            }

            $event = $this->verifyWebhookPayload($payload, $signature);
            $verify = $this->resolveWebhookResult($event);
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
            Log::warning('Stripe notify rejected', [
                'uuid' => $this->getConfig('uuid'),
                'message' => $e->getMessage(),
            ]);
            return response($e->getMessage(), 400);
        } catch (\Throwable $e) {
            Log::error($e);
            return response('fail', 500);
        }
    }

    private function createCheckoutSession(array $context): array
    {
        $payload = [
            'mode' => 'payment',
            // Let Stripe use Dashboard-managed dynamic payment methods instead of forcing cards only.
            'success_url' => $context['return_url'],
            'cancel_url' => $context['return_url'],
            'client_reference_id' => $context['trade_no'],
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($this->getCurrency()),
            'line_items[0][price_data][unit_amount]' => $context['total_amount'],
            'line_items[0][price_data][product_data][name]' => $this->getProductName(),
            'line_items[0][price_data][product_data][description]' => sprintf('Order %s', $context['trade_no']),
            'metadata[trade_no]' => $context['trade_no'],
            'metadata[user_id]' => (string) $context['user_id'],
            'payment_intent_data[metadata][trade_no]' => $context['trade_no'],
            'payment_intent_data[metadata][user_id]' => (string) $context['user_id'],
            'expires_at' => $this->resolveSessionExpiresAt(),
        ];

        return $this->postForm('/checkout/sessions', $payload);
    }

    private function resolveWebhookResult(array $event): array|string|bool
    {
        $type = (string) ($event['type'] ?? '');

        return match ($type) {
            'checkout.session.completed' => $this->resolveCompletedSession($event),
            'checkout.session.expired',
            'checkout.session.async_payment_failed',
            'payment_intent.payment_failed',
            'payment_intent.canceled' => 'success',
            default => 'success',
        };
    }

    private function resolveCompletedSession(array $event): array|string|bool
    {
        $session = $event['data']['object'] ?? null;
        if (!is_array($session) || ($session['object'] ?? '') !== 'checkout.session') {
            return false;
        }

        if (($session['payment_status'] ?? '') !== 'paid') {
            return 'success';
        }

        $tradeNo = $this->extractTradeNo($session);
        if ($tradeNo === null) {
            return false;
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            Log::warning('Stripe webhook ignored because order does not exist', [
                'trade_no' => $tradeNo,
                'event_id' => $event['id'] ?? null,
            ]);
            return 'success';
        }

        if ($order->status !== Order::STATUS_PENDING) {
            return 'success';
        }

        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        $sessionAmount = (int) ($session['amount_total'] ?? -1);
        if ($sessionAmount !== $expectedAmount) {
            Log::warning('Stripe webhook amount mismatch', [
                'trade_no' => $tradeNo,
                'expected_amount' => $expectedAmount,
                'session_amount' => $sessionAmount,
            ]);
            return false;
        }

        $sessionCurrency = strtoupper((string) ($session['currency'] ?? ''));
        if ($sessionCurrency !== $this->getCurrency()) {
            Log::warning('Stripe webhook currency mismatch', [
                'trade_no' => $tradeNo,
                'expected_currency' => $this->getCurrency(),
                'session_currency' => $sessionCurrency,
            ]);
            return false;
        }

        $callbackNo = (string) ($event['id'] ?? $session['payment_intent'] ?? $session['id'] ?? '');
        if ($callbackNo === '') {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'custom_result' => 'success',
        ];
    }

    private function extractTradeNo(array $session): ?string
    {
        $tradeNo = $session['metadata']['trade_no'] ?? $session['client_reference_id'] ?? null;
        if (!is_string($tradeNo) || $tradeNo === '') {
            return null;
        }

        return $tradeNo;
    }

    private function verifyWebhookPayload(string $payload, string $signatureHeader): array
    {
        $pairs = [];
        foreach (explode(',', $signatureHeader) as $item) {
            [$key, $value] = array_pad(explode('=', trim($item), 2), 2, null);
            if ($key !== null && $value !== null) {
                $pairs[$key][] = $value;
            }
        }

        $timestamp = isset($pairs['t'][0]) ? (int) $pairs['t'][0] : 0;
        $signatures = $pairs['v1'] ?? [];

        if ($timestamp <= 0 || empty($signatures)) {
            throw new ApiException('Invalid Stripe signature header');
        }

        $tolerance = $this->resolveWebhookTolerance();
        if (abs(time() - $timestamp) > $tolerance) {
            throw new ApiException('Stripe webhook timestamp expired');
        }

        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->getWebhookSecret());
        $verified = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expectedSignature, $signature)) {
                $verified = true;
                break;
            }
        }

        if (!$verified) {
            throw new ApiException('Invalid Stripe webhook signature');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new ApiException('Invalid Stripe webhook payload');
        }

        return $event;
    }

    private function postForm(string $path, array $payload): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => self::API_BASE_URL . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->getSecretKey(),
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($curl);
        if ($response === false) {
            $message = curl_error($curl) ?: 'Stripe request failed';
            curl_close($curl);
            throw new ApiException($message);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ApiException('Invalid Stripe response payload');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $decoded['error']['message'] ?? 'Stripe request failed';
            throw new ApiException($message);
        }

        return $decoded;
    }

    private function resolveSessionExpiresAt(): int
    {
        $minutes = (int) ($this->getConfig('session_expire_minutes') ?: self::DEFAULT_SESSION_EXPIRE_MINUTES);
        $minutes = max(30, min(1440, $minutes));

        return time() + ($minutes * 60);
    }

    private function resolveWebhookTolerance(): int
    {
        $tolerance = (int) ($this->getConfig('webhook_tolerance_seconds') ?: self::DEFAULT_WEBHOOK_TOLERANCE_SECONDS);

        return max(60, $tolerance);
    }

    private function getCurrency(): string
    {
        $currency = strtoupper(trim((string) $this->getConfig('currency', admin_setting('currency', 'USD'))));
        if ($currency === '') {
            throw new ApiException('Stripe currency is required');
        }

        return $currency;
    }

    private function getProductName(): string
    {
        $productName = trim((string) $this->getConfig('product_name', (string) admin_setting('app_name', 'Xboard') . ' 订阅订单'));

        return $productName !== '' ? $productName : 'Xboard 订阅订单';
    }

    private function getSecretKey(): string
    {
        $secretKey = trim((string) $this->getConfig('secret_key'));
        if ($secretKey === '') {
            throw new ApiException('Stripe secret key is required');
        }

        return $secretKey;
    }

    private function getWebhookSecret(): string
    {
        $webhookSecret = trim((string) $this->getConfig('webhook_secret'));
        if ($webhookSecret === '') {
            throw new ApiException('Stripe webhook secret is required');
        }

        return $webhookSecret;
    }

    private function getWebhookPayload(array $params = []): string
    {
        try {
            $request = request();
            $requestContent = $request->getContent();
            if (is_string($requestContent) && $requestContent !== '') {
                return $requestContent;
            }
        } catch (\Throwable) {
        }

        $requestContent = $params['__request_content'] ?? null;
        if (is_string($requestContent) && $requestContent !== '') {
            return $requestContent;
        }

        $payload = file_get_contents('php://input');

        return is_string($payload) ? $payload : '';
    }

    private function getRequestHeader(string $name, array $params = []): ?string
    {
        try {
            $headerValue = request()->header($name);
            if (is_string($headerValue) && $headerValue !== '') {
                return $headerValue;
            }
        } catch (\Throwable) {
        }

        $requestHeaders = $params['__request_headers'] ?? null;
        if (is_array($requestHeaders)) {
            foreach ($requestHeaders as $headerName => $value) {
                if (strcasecmp((string) $headerName, $name) === 0) {
                    return is_array($value) ? implode(',', $value) : (string) $value;
                }
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $headerName => $value) {
                if (strcasecmp($headerName, $name) === 0) {
                    return is_array($value) ? implode(',', $value) : (string) $value;
                }
            }
        }

        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$serverKey] ?? null;

        return is_string($value) ? $value : null;
    }

    private function markOrderPaid(string $tradeNo, string $callbackNo): bool
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            Log::warning('Stripe notify ignored because order does not exist during mark paid', [
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
