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

    /** @var list<string> */
    public array $acknowledgedTokens = [];

    private static ?self $shared = null;

    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public static function reset(): void
    {
        self::$shared = new self();
    }

    public function getSubscription(string $package, string $token): ?array
    {
        unset($package);
        return self::fixture($token);
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
