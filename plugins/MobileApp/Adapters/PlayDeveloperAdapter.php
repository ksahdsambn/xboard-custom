<?php

namespace Plugin\MobileApp\Adapters;

/**
 * Development Play Developer API adapter. Isolated tests use an in-process
 * fixture catalog. This class does not call production Google endpoints.
 */
final class PlayDeveloperAdapter
{
    public const PACKAGE = 'dev.xboard.xboard_mobile';

    public const PRODUCT = 'dev.xboard.sub.monthly';

    public int $acknowledgeCalls = 0;

    public int $failNextLookups = 0;

    /** @var list<string> */
    public array $acknowledgedTokens = [];

    /** @var array<string, array> */
    private array $overrides = [];

    private static ?self $shared = null;

    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public static function reset(): void
    {
        self::$shared = new self();
    }

    public function failNext(int $count = 1): void
    {
        $this->failNextLookups = max(0, $count);
    }

    public function setStatus(string $token, string $status, array $extra = []): void
    {
        $base = self::fixture($token) ?? self::fixture('tok-purchased');
        if ($base === null) {
            $base = [
                'packageName' => self::PACKAGE,
                'productId' => self::PRODUCT,
                'acknowledgementState' => 'acknowledged',
                'isRenewal' => false,
                'voided' => null,
                'externalSubscriptionId' => 'sub-' . substr(hash('sha256', $token), 0, 16),
                'expiryTime' => '2099-01-01T00:00:00Z',
                'evidenceClass' => 'non-production-simulation',
            ];
        }
        $this->overrides[$token] = array_merge($base, $extra, ['playStatus' => $status]);
    }

    public function getSubscription(string $package, string $token): ?array
    {
        unset($package);
        if ($this->failNextLookups > 0) {
            $this->failNextLookups--;
            throw new \RuntimeException('DEVELOPER_API_UNAVAILABLE');
        }
        if (isset($this->overrides[$token])) {
            return $this->overrides[$token];
        }
        return self::fixture($token);
    }

    public function getSubscriptionByHash(string $hash): ?array
    {
        $tokens = array_unique(array_merge(array_keys($this->overrides), [
            'tok-pending', 'tok-purchased', 'tok-renewal', 'tok-canceled', 'tok-expired',
            'tok-refunded', 'tok-revoked', 'tok-grace', 'tok-hold', 'tok-restore',
            'tok-wrong-pkg', 'tok-wrong-product', 'tok-rtdn-live',
        ]));
        foreach ($tokens as $token) {
            if (hash('sha256', $token) === $hash) {
                return $this->getSubscription(self::PACKAGE, $token);
            }
        }
        return null;
    }

    public function acknowledge(string $package, string $productId, string $token): void
    {
        unset($package, $productId);
        $this->acknowledgeCalls++;
        $this->acknowledgedTokens[] = hash('sha256', $token);
    }

    public static function fixture(string $token): ?array
    {
        $catalog = [
            'tok-pending' => ['state' => 'pending', 'ack' => 'pending', 'renewal' => false, 'voided' => null],
            'tok-purchased' => ['state' => 'purchased', 'ack' => 'pending', 'renewal' => false, 'voided' => null],
            'tok-renewal' => ['state' => 'purchased', 'ack' => 'acknowledged', 'renewal' => true, 'voided' => null],
            'tok-canceled' => ['state' => 'canceled', 'ack' => 'acknowledged', 'renewal' => false, 'voided' => null],
            'tok-expired' => ['state' => 'expired', 'ack' => 'acknowledged', 'renewal' => false, 'voided' => null],
            'tok-refunded' => ['state' => 'refunded', 'ack' => 'acknowledged', 'renewal' => false, 'voided' => 'refund'],
            'tok-revoked' => ['state' => 'revoked', 'ack' => 'acknowledged', 'renewal' => false, 'voided' => 'revoke'],
            'tok-grace' => ['state' => 'grace', 'ack' => 'acknowledged', 'renewal' => false, 'voided' => null],
            'tok-hold' => ['state' => 'account_hold', 'ack' => 'acknowledged', 'renewal' => false, 'voided' => null],
            'tok-restore' => ['state' => 'restored', 'ack' => 'acknowledged', 'renewal' => false, 'voided' => null],
            'tok-wrong-pkg' => ['state' => 'purchased', 'ack' => 'pending', 'renewal' => false, 'voided' => null, 'packageName' => 'com.other.app'],
            'tok-wrong-product' => ['state' => 'purchased', 'ack' => 'pending', 'renewal' => false, 'voided' => null, 'productId' => 'sku.unknown'],
            'tok-rtdn-live' => ['state' => 'purchased', 'ack' => 'acknowledged', 'renewal' => false, 'voided' => null],
        ];
        if (!isset($catalog[$token])) {
            return null;
        }
        $row = $catalog[$token];
        return [
            'packageName' => $row['packageName'] ?? self::PACKAGE,
            'productId' => $row['productId'] ?? self::PRODUCT,
            'playStatus' => $row['state'],
            'acknowledgementState' => $row['ack'],
            'isRenewal' => (bool) $row['renewal'],
            'voided' => $row['voided'],
            'externalSubscriptionId' => 'sub-' . substr(hash('sha256', $token), 0, 16),
            'expiryTime' => '2099-01-01T00:00:00Z',
            'evidenceClass' => 'non-production-simulation',
        ];
    }
}
