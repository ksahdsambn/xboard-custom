<?php

namespace Plugin\WalletCenter\Services;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\Request;
use Plugin\WalletCenter\Models\TopupOrder;

class TopupGatewayService
{
    private const STRIPE_DEFAULT_TOLERANCE_SECONDS = 300;

    public function __construct(
        protected PluginManager $pluginManager
    ) {
    }

    public function supportedMethods(): array
    {
        return [
            'Stripe',
            'BEpusdt',
        ];
    }

    public function supportsMethod(?string $method): bool
    {
        return in_array((string) $method, $this->supportedMethods(), true);
    }

    public function createPayment(Payment $payment, TopupOrder $order, ?string $returnUrl = null): array
    {
        if (!$this->supportsMethod($payment->payment)) {
            throw new ApiException('WalletCenter topup payment method is not supported');
        }

        $plugin = $this->resolvePaymentPlugin($payment);

        return $plugin->pay([
            'notify_url' => $this->buildNotifyUrl($payment),
            'return_url' => $returnUrl ?: $this->buildReturnUrl($order),
            'trade_no' => $order->trade_no,
            'total_amount' => (int) $order->amount + (int) $order->handling_amount,
            'user_id' => $order->user_id,
            'stripe_token' => null,
        ]);
    }

    public function handleNotify(Payment $payment, Request $request): array
    {
        return match ((string) $payment->payment) {
            'Stripe' => $this->handleStripeNotify($payment, $request),
            'BEpusdt' => $this->handleBepusdtNotify($payment, $request),
            default => throw new ApiException('WalletCenter topup payment method is not supported'),
        };
    }

    public function buildNotifyUrl(Payment $payment): string
    {
        $notifyUrl = url("/api/v1/wallet-center/topup/notify/{$payment->payment}/{$payment->uuid}");
        $notifyDomain = trim((string) ($payment->notify_domain ?? ''));
        if ($notifyDomain === '') {
            return $notifyUrl;
        }

        $parsedUrl = parse_url($notifyUrl);
        $path = $parsedUrl['path'] ?? '';
        $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';

        return rtrim($notifyDomain, '/') . $path . $query;
    }

    public function buildReturnUrl(TopupOrder $order): string
    {
        $fallbackUrl = $this->buildDefaultReturnUrl($order);
        $referer = trim((string) request()->header('Referer', ''));
        if ($referer === '') {
            return $fallbackUrl;
        }

        $refererOrigin = $this->extractOrigin($referer);
        if ($refererOrigin !== null && $this->isTrustedReturnOrigin($refererOrigin)) {
            return $referer;
        }

        return $fallbackUrl;
    }

    protected function resolvePaymentPlugin(Payment $payment): PaymentInterface
    {
        $this->pluginManager->initializeEnabledPlugins();

        $methodMap = (new PaymentService('temp'))->getAvailablePaymentMethods();
        $method = $methodMap[$payment->payment] ?? null;
        $pluginCode = $method['plugin_code'] ?? null;
        if (!is_string($pluginCode) || $pluginCode === '') {
            throw new ApiException('WalletCenter topup payment plugin is not available');
        }

        foreach ($this->pluginManager->getEnabledPaymentPlugins() as $plugin) {
            if ($plugin->getPluginCode() !== $pluginCode) {
                continue;
            }

            if (!$plugin instanceof PaymentInterface) {
                throw new ApiException('WalletCenter topup payment plugin does not implement payment interface');
            }

            $plugin->setConfig($this->normalizePaymentConfig($payment));
            return $plugin;
        }

        throw new ApiException('WalletCenter topup payment plugin is not enabled');
    }

    protected function normalizePaymentConfig(Payment $payment): array
    {
        $config = is_array($payment->config) ? $payment->config : [];
        $config['enable'] = (bool) $payment->enable;
        $config['id'] = $payment->id;
        $config['uuid'] = $payment->uuid;
        $config['notify_domain'] = $payment->notify_domain ?? '';

        return $config;
    }

    protected function handleStripeNotify(Payment $payment, Request $request): array
    {
        $payload = $request->getContent();
        $signature = trim((string) $request->header('Stripe-Signature'));
        if ($payload === '' || $signature === '') {
            return $this->failureResponse('verify error');
        }

        try {
            $event = $this->verifyStripeWebhook(
                $payload,
                $signature,
                trim((string) ($payment->config['webhook_secret'] ?? '')),
                $this->resolveStripeTolerance($payment)
            );
        } catch (ApiException $exception) {
            return [
                'response' => response($exception->getMessage(), 400),
                'status' => null,
                'trade_no' => null,
                'callback_no' => null,
                'meta' => [
                    'gateway' => 'Stripe',
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        $type = (string) ($event['type'] ?? '');

        return match ($type) {
            'checkout.session.completed' => $this->resolveStripeCompleted($payment, $event),
            'checkout.session.expired' => $this->resolveStripeState($event, TopupOrder::STATUS_EXPIRED),
            'checkout.session.async_payment_failed',
            'payment_intent.payment_failed',
            'payment_intent.canceled' => $this->resolveStripeState($event, TopupOrder::STATUS_CANCELLED),
            default => [
                'response' => 'success',
                'status' => null,
                'trade_no' => null,
                'callback_no' => null,
                'meta' => [
                    'gateway' => 'Stripe',
                    'event_id' => $event['id'] ?? null,
                    'event_type' => $type,
                    'ignored' => true,
                ],
            ],
        };
    }

    protected function resolveStripeCompleted(Payment $payment, array $event): array
    {
        $session = $event['data']['object'] ?? null;
        if (!is_array($session) || ($session['object'] ?? '') !== 'checkout.session') {
            return $this->failureResponse('verify error');
        }

        if (($session['payment_status'] ?? '') !== 'paid') {
            return [
                'response' => 'success',
                'status' => TopupOrder::STATUS_PENDING,
                'trade_no' => $this->extractStripeTradeNo($session),
                'callback_no' => null,
                'meta' => [
                    'gateway' => 'Stripe',
                    'event_id' => $event['id'] ?? null,
                    'event_type' => $event['type'] ?? null,
                    'payment_status' => $session['payment_status'] ?? null,
                ],
            ];
        }

        $tradeNo = $this->extractStripeTradeNo($session);
        if (!$tradeNo) {
            return $this->failureResponse('verify error');
        }

        $order = TopupOrder::query()->where('trade_no', $tradeNo)->first();
        if (!$order) {
            return [
                'response' => 'success',
                'status' => null,
                'trade_no' => $tradeNo,
                'callback_no' => null,
                'meta' => [
                    'gateway' => 'Stripe',
                    'event_id' => $event['id'] ?? null,
                    'event_type' => $event['type'] ?? null,
                    'ignored' => true,
                ],
            ];
        }

        $expectedAmount = (int) $order->amount + (int) $order->handling_amount;
        $amountTotal = (int) ($session['amount_total'] ?? -1);
        if ($amountTotal !== $expectedAmount) {
            return [
                'response' => response('amount mismatch', 400),
                'status' => null,
                'trade_no' => $tradeNo,
                'callback_no' => null,
                'meta' => [
                    'gateway' => 'Stripe',
                    'event_id' => $event['id'] ?? null,
                    'event_type' => $event['type'] ?? null,
                    'expected_amount' => $expectedAmount,
                    'actual_amount' => $amountTotal,
                ],
            ];
        }

        $expectedCurrency = strtoupper(trim((string) ($payment->config['currency'] ?? admin_setting('currency', 'USD'))));
        $sessionCurrency = strtoupper((string) ($session['currency'] ?? ''));
        if ($sessionCurrency !== $expectedCurrency) {
            return [
                'response' => response('currency mismatch', 400),
                'status' => null,
                'trade_no' => $tradeNo,
                'callback_no' => null,
                'meta' => [
                    'gateway' => 'Stripe',
                    'event_id' => $event['id'] ?? null,
                    'event_type' => $event['type'] ?? null,
                    'expected_currency' => $expectedCurrency,
                    'actual_currency' => $sessionCurrency,
                ],
            ];
        }

        $callbackNo = (string) ($event['id'] ?? $session['payment_intent'] ?? $session['id'] ?? '');
        if ($callbackNo === '') {
            return $this->failureResponse('verify error');
        }

        return [
            'response' => 'success',
            'status' => TopupOrder::STATUS_PAID,
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'meta' => [
                'gateway' => 'Stripe',
                'event_id' => $event['id'] ?? null,
                'event_type' => $event['type'] ?? null,
                'payment_intent' => $session['payment_intent'] ?? null,
                'checkout_session_id' => $session['id'] ?? null,
            ],
        ];
    }

    protected function resolveStripeState(array $event, int $status): array
    {
        $object = $event['data']['object'] ?? null;
        $tradeNo = is_array($object) ? $this->extractStripeTradeNo($object) : null;
        $callbackNo = (string) ($event['id'] ?? $object['payment_intent'] ?? $object['id'] ?? '');

        return [
            'response' => 'success',
            'status' => $status,
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo !== '' ? $callbackNo : null,
            'meta' => [
                'gateway' => 'Stripe',
                'event_id' => $event['id'] ?? null,
                'event_type' => $event['type'] ?? null,
                'payment_status' => $object['payment_status'] ?? null,
            ],
        ];
    }

    protected function extractStripeTradeNo(array $payload): ?string
    {
        $tradeNo = $payload['metadata']['trade_no'] ?? $payload['client_reference_id'] ?? null;

        return is_string($tradeNo) && $tradeNo !== '' ? $tradeNo : null;
    }

    protected function verifyStripeWebhook(string $payload, string $signatureHeader, string $secret, int $tolerance): array
    {
        if ($secret === '') {
            throw new ApiException('Stripe webhook secret is required');
        }

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

        if (abs(time() - $timestamp) > $tolerance) {
            throw new ApiException('Stripe webhook timestamp expired');
        }

        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expectedSignature, (string) $signature)) {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    return $decoded;
                }

                throw new ApiException('Invalid Stripe webhook payload');
            }
        }

        throw new ApiException('Invalid Stripe webhook signature');
    }

    protected function resolveStripeTolerance(Payment $payment): int
    {
        $tolerance = (int) ($payment->config['webhook_tolerance_seconds'] ?? self::STRIPE_DEFAULT_TOLERANCE_SECONDS);

        return max(60, $tolerance);
    }

    protected function handleBepusdtNotify(Payment $payment, Request $request): array
    {
        $payload = $this->extractBepusdtPayload($request);
        if ($payload === []) {
            return $this->failureResponse('verify error');
        }

        $apiToken = trim((string) ($payment->config['api_token'] ?? ''));
        if ($apiToken === '') {
            return [
                'response' => response('BEpusdt api token is required', 400),
                'status' => null,
                'trade_no' => null,
                'callback_no' => null,
                'meta' => [
                    'gateway' => 'BEpusdt',
                    'error' => 'BEpusdt api token is required',
                ],
            ];
        }

        if (!$this->verifyBepusdtSignature($payload, $apiToken)) {
            return [
                'response' => response('Invalid BEpusdt signature', 400),
                'status' => null,
                'trade_no' => $payload['order_id'] ?? null,
                'callback_no' => null,
                'meta' => [
                    'gateway' => 'BEpusdt',
                    'error' => 'Invalid BEpusdt signature',
                ],
            ];
        }

        $status = (int) ($payload['status'] ?? 0);

        return match ($status) {
            1 => [
                'response' => 'success',
                'status' => TopupOrder::STATUS_PENDING,
                'trade_no' => $this->extractBepusdtTradeNo($payload),
                'callback_no' => $this->extractBepusdtCallbackNo($payload),
                'meta' => [
                    'gateway' => 'BEpusdt',
                    'status' => $status,
                    'trade_id' => $payload['trade_id'] ?? null,
                ],
            ],
            2 => $this->resolveBepusdtPaid($payload),
            3 => [
                'response' => 'success',
                'status' => TopupOrder::STATUS_EXPIRED,
                'trade_no' => $this->extractBepusdtTradeNo($payload),
                'callback_no' => $this->extractBepusdtCallbackNo($payload),
                'meta' => [
                    'gateway' => 'BEpusdt',
                    'status' => $status,
                    'trade_id' => $payload['trade_id'] ?? null,
                ],
            ],
            default => [
                'response' => 'success',
                'status' => null,
                'trade_no' => $this->extractBepusdtTradeNo($payload),
                'callback_no' => $this->extractBepusdtCallbackNo($payload),
                'meta' => [
                    'gateway' => 'BEpusdt',
                    'status' => $status,
                    'ignored' => true,
                ],
            ],
        };
    }

    protected function resolveBepusdtPaid(array $payload): array
    {
        $tradeNo = $this->extractBepusdtTradeNo($payload);
        $callbackNo = $this->extractBepusdtCallbackNo($payload);
        if ($tradeNo === null || $callbackNo === null) {
            return $this->failureResponse('verify error');
        }

        $order = TopupOrder::query()->where('trade_no', $tradeNo)->first();
        if (!$order) {
            return [
                'response' => 'success',
                'status' => null,
                'trade_no' => $tradeNo,
                'callback_no' => $callbackNo,
                'meta' => [
                    'gateway' => 'BEpusdt',
                    'status' => $payload['status'] ?? null,
                    'ignored' => true,
                ],
            ];
        }

        $expectedAmount = (int) $order->amount + (int) $order->handling_amount;
        $callbackAmount = $this->toAmountInCents($payload['amount'] ?? null);
        if ($callbackAmount === null || $callbackAmount !== $expectedAmount) {
            return [
                'response' => response('amount mismatch', 400),
                'status' => null,
                'trade_no' => $tradeNo,
                'callback_no' => $callbackNo,
                'meta' => [
                    'gateway' => 'BEpusdt',
                    'status' => $payload['status'] ?? null,
                    'expected_amount' => $expectedAmount,
                    'actual_amount' => $callbackAmount,
                ],
            ];
        }

        return [
            'response' => 'success',
            'status' => TopupOrder::STATUS_PAID,
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'meta' => [
                'gateway' => 'BEpusdt',
                'status' => $payload['status'] ?? null,
                'trade_id' => $payload['trade_id'] ?? null,
                'block_transaction_id' => $payload['block_transaction_id'] ?? null,
            ],
        ];
    }

    protected function extractBepusdtPayload(Request $request): array
    {
        $jsonPayload = $request->json()->all();
        if (is_array($jsonPayload) && $jsonPayload !== []) {
            return $this->stripInternalPayloadKeys($jsonPayload);
        }

        $rawPayload = $request->getContent();
        if (is_string($rawPayload) && $rawPayload !== '') {
            $decoded = json_decode($rawPayload, true);
            if (is_array($decoded) && $decoded !== []) {
                return $this->stripInternalPayloadKeys($decoded);
            }
        }

        return $this->stripInternalPayloadKeys($request->all());
    }

    protected function stripInternalPayloadKeys(array $payload): array
    {
        unset($payload['__request_content'], $payload['__request_headers']);

        return $payload;
    }

    protected function verifyBepusdtSignature(array $payload, string $apiToken): bool
    {
        $signature = strtolower(trim((string) ($payload['signature'] ?? '')));
        if ($signature === '') {
            return false;
        }

        return hash_equals($this->signBepusdtPayload($payload, $apiToken), $signature);
    }

    protected function signBepusdtPayload(array $payload, string $apiToken): string
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

            $pairs[] = $key . '=' . $this->stringifyBepusdtValue($value);
        }

        return md5(implode('&', $pairs) . $apiToken);
    }

    protected function stringifyBepusdtValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string) $value;
        }

        return (string) $value;
    }

    protected function extractBepusdtTradeNo(array $payload): ?string
    {
        $tradeNo = trim((string) ($payload['order_id'] ?? ''));

        return $tradeNo !== '' ? $tradeNo : null;
    }

    protected function extractBepusdtCallbackNo(array $payload): ?string
    {
        $callbackNo = trim((string) ($payload['block_transaction_id'] ?? $payload['trade_id'] ?? ''));

        return $callbackNo !== '' ? $callbackNo : null;
    }

    protected function toAmountInCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    protected function failureResponse(string $message): array
    {
        return [
            'response' => response($message, 422),
            'status' => null,
            'trade_no' => null,
            'callback_no' => null,
            'meta' => [],
        ];
    }

    protected function buildDefaultReturnUrl(TopupOrder $order): string
    {
        return $this->resolveSafeBaseUrl() . '/#/wallet?topup_trade_no=' . $order->trade_no;
    }

    protected function resolveSafeBaseUrl(): string
    {
        $configuredOrigin = $this->extractOrigin((string) config('app.url'));
        if ($configuredOrigin !== null) {
            return $configuredOrigin;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/');
    }

    protected function isTrustedReturnOrigin(string $origin): bool
    {
        $trustedOrigins = array_values(array_unique(array_filter([
            $this->extractOrigin((string) config('app.url')),
            rtrim(request()->getSchemeAndHttpHost(), '/'),
        ])));

        return in_array($origin, $trustedOrigins, true);
    }

    protected function extractOrigin(string $url): ?string
    {
        $value = trim($url);
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }
}
