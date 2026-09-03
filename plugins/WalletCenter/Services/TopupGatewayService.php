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
    public function __construct(
        protected PluginManager $pluginManager
    ) {
    }

    public function supportedMethods(): array
    {
        $this->pluginManager->initializeEnabledPlugins();

        return array_keys((new PaymentService('temp'))->getAvailablePaymentMethods());
    }

    public function supportsMethod(?string $method): bool
    {
        $method = (string) $method;
        if ($method === '') {
            return false;
        }

        return in_array($method, $this->supportedMethods(), true);
    }

    public function createPayment(Payment $payment, TopupOrder $order, ?string $returnUrl = null): array
    {
        if (!$this->supportsMethod($payment->payment)) {
            throw new ApiException('WalletCenter topup payment method is not supported');
        }

        $plugin = $this->resolvePaymentPlugin($payment);
        $appName = (string) admin_setting('app_name', 'Xboard');

        return $plugin->pay([
            'notify_url' => $this->buildNotifyUrl($payment),
            'return_url' => $returnUrl ?: $this->buildReturnUrl($order),
            'trade_no' => $order->trade_no,
            'total_amount' => (int) $order->amount + (int) $order->handling_amount,
            'user_id' => $order->user_id,
            'stripe_token' => null,
            'product_name' => $appName . ' 余额充值',
        ]);
    }

    public function handleNotify(Payment $payment, Request $request): array
    {
        $plugin = $this->resolvePaymentPlugin($payment);
        if (method_exists($plugin, 'inspectNotification')) {
            $params = $request->all();
            if (!is_array($params)) {
                $params = [];
            }
            $params['__request_content'] = $request->getContent();
            $params['__request_headers'] = $request->headers->all();

            return $this->mapInspectedNotification($payment, $plugin->inspectNotification($params));
        }

        return $this->handleGenericNotify($payment, $request);
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
            return $refererOrigin . $this->appUrlPath() . '/#/profile?section=topup&topup_trade_no=' . rawurlencode((string) $order->trade_no);
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

    protected function mapInspectedNotification(Payment $payment, array $inspection): array
    {
        if (!($inspection['ok'] ?? false)) {
            $status = (int) ($inspection['http_status'] ?? 422);

            return [
                'response' => response((string) ($inspection['error'] ?? 'verify error'), $status > 0 ? $status : 422),
                'status' => null,
                'trade_no' => $inspection['trade_no'] ?? null,
                'callback_no' => $inspection['callback_no'] ?? null,
                'meta' => $inspection['meta'] ?? ['error' => $inspection['error'] ?? 'verify error'],
            ];
        }

        $outcome = (string) ($inspection['outcome'] ?? 'ignored');
        $status = match ($outcome) {
            'paid' => TopupOrder::STATUS_PAID,
            'pending' => TopupOrder::STATUS_PENDING,
            'expired' => TopupOrder::STATUS_EXPIRED,
            'cancelled' => TopupOrder::STATUS_CANCELLED,
            'refunded' => TopupOrder::STATUS_REFUNDED,
            default => null,
        };

        $tradeNo = $inspection['trade_no'] ?? null;
        $callbackNo = trim((string) ($inspection['callback_no'] ?? ''));
        if ($status === TopupOrder::STATUS_PAID) {
            if (!is_string($tradeNo) || $tradeNo === '' || $callbackNo === '') {
                return [
                    'response' => response('verify error', 422),
                    'status' => null,
                    'trade_no' => is_string($tradeNo) ? $tradeNo : null,
                    'callback_no' => $callbackNo !== '' ? $callbackNo : null,
                    'meta' => [
                        'gateway' => $payment->payment,
                        'error' => 'paid notification is missing trade_no or callback_no',
                    ],
                ];
            }

            $order = TopupOrder::query()->where('trade_no', $tradeNo)->first();
            if ($order) {
                $config = $this->normalizePaymentConfig($payment);
                $expectedAmount = (int) $order->amount + (int) $order->handling_amount;
                $actualAmount = $inspection['amount'] ?? null;
                if ($payment->payment === 'Stripe') {
                    $currency = strtoupper(trim((string) ($config['currency'] ?? admin_setting('currency', 'USD'))));
                    $expectedAmount = $this->toStripeUnitAmount($expectedAmount, $currency);
                    $actualCurrency = strtoupper(trim((string) ($inspection['currency'] ?? '')));
                    if ($actualCurrency !== '' && $actualCurrency !== $currency) {
                        return [
                            'response' => response('currency mismatch', 400),
                            'status' => null,
                            'trade_no' => $tradeNo,
                            'callback_no' => $callbackNo,
                            'meta' => [
                                'gateway' => $payment->payment,
                                'expected_currency' => $currency,
                                'actual_currency' => $actualCurrency,
                            ],
                        ];
                    }
                }
                if ($actualAmount === null || (int) $actualAmount !== $expectedAmount) {
                    return [
                        'response' => response('amount mismatch', 400),
                        'status' => null,
                        'trade_no' => $tradeNo,
                        'callback_no' => $callbackNo,
                        'meta' => [
                            'gateway' => $payment->payment,
                            'expected_amount' => $expectedAmount,
                            'actual_amount' => $actualAmount,
                        ],
                    ];
                }
            }
        }

        return [
            'response' => 'success',
            'status' => $status,
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo !== '' ? $callbackNo : ($inspection['callback_no'] ?? null),
            'meta' => is_array($inspection['meta'] ?? null) ? $inspection['meta'] : ['gateway' => $payment->payment],
        ];
    }

    protected function normalizePaymentConfig(Payment $payment): array
    {
        $config = $payment->config;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($config)) {
            $config = [];
        }

        $config['enable'] = (bool) $payment->enable;
        $config['id'] = $payment->id;
        $config['uuid'] = $payment->uuid;
        $config['notify_domain'] = $payment->notify_domain ?? '';

        return $config;
    }


    protected function toStripeUnitAmount(int $amountInCents, string $currency): int
    {
        $zeroDecimal = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];

        if (in_array(strtoupper($currency), $zeroDecimal, true)) {
            return (int) round($amountInCents / 100);
        }

        return $amountInCents;
    }

    protected function handleGenericNotify(Payment $payment, Request $request): array
    {
        $plugin = $this->resolvePaymentPlugin($payment);
        $params = $request->all();
        if ($params === []) {
            $json = $request->json()->all();
            $params = is_array($json) ? $json : [];
        }

        try {
            $verify = $plugin->notify($params);
        } catch (ApiException $exception) {
            return [
                'response' => response($exception->getMessage(), 400),
                'status' => null,
                'trade_no' => null,
                'callback_no' => null,
                'meta' => [
                    'gateway' => $payment->payment,
                    'error' => $exception->getMessage(),
                ],
            ];
        } catch (\Throwable $exception) {
            if (!str_contains($exception::class, 'InterceptResponseException')) {
                throw $exception;
            }

            return [
                'response' => 'success',
                'status' => null,
                'trade_no' => null,
                'callback_no' => null,
                'meta' => [
                    'gateway' => $payment->payment,
                    'intercepted' => true,
                ],
            ];
        }

        if ($verify === false) {
            return $this->failureResponse('verify error');
        }

        if ($verify instanceof \Symfony\Component\HttpFoundation\Response) {
            return [
                'response' => $verify,
                'status' => null,
                'trade_no' => null,
                'callback_no' => null,
                'meta' => [
                    'gateway' => $payment->payment,
                ],
            ];
        }

        if (!is_array($verify) || empty($verify['trade_no'])) {
            return [
                'response' => is_string($verify) ? $verify : 'success',
                'status' => null,
                'trade_no' => null,
                'callback_no' => null,
                'meta' => [
                    'gateway' => $payment->payment,
                    'ignored' => true,
                ],
            ];
        }

        return [
            'response' => $verify['custom_result'] ?? 'success',
            'status' => TopupOrder::STATUS_PAID,
            'trade_no' => (string) $verify['trade_no'],
            'callback_no' => isset($verify['callback_no']) ? (string) $verify['callback_no'] : null,
            'meta' => [
                'gateway' => $payment->payment,
            ],
        ];
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
        return $this->resolveSafeBaseUrl() . '/#/profile?section=topup&topup_trade_no=' . rawurlencode((string) $order->trade_no);
    }

    protected function resolveSafeBaseUrl(): string
    {
        $configured = trim((string) config('app.url'));
        if ($this->extractOrigin($configured) !== null) {
            return rtrim($configured, '/');
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/') . $this->appUrlPath();
    }

    protected function appUrlPath(): string
    {
        $parts = parse_url((string) config('app.url'));
        if (!is_array($parts) || !isset($parts['path'])) {
            return '';
        }

        $path = rtrim((string) $parts['path'], '/');

        return $path === '/' ? '' : $path;
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
