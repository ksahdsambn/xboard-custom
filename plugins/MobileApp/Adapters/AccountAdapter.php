<?php

namespace Plugin\MobileApp\Adapters;

use App\Models\User;
use Plugin\MobileApp\Services\EntitlementService;
use Plugin\MobileApp\Support\MobileLogRedactor;

final class AccountAdapter
{
    public const FORBIDDEN_FIELDS = [
        'userId',
        'id',
        'uuid',
        'token',
        'subscriptionToken',
        'auth_data',
        'privateKey',
        'is_admin',
        'balance',
        'commission_balance',
    ];

    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    public function snapshot(User $user, array $clientClaims = []): array
    {
        $dto = [
            'opaqueAccountId' => AuthAdapter::opaqueAccountId($user),
            'emailMasked' => self::maskEmail((string) $user->email),
            'entitlement' => $this->entitlements->forUser($user, $clientClaims),
        ];
        foreach (self::FORBIDDEN_FIELDS as $field) {
            unset($dto[$field]);
        }
        MobileLogRedactor::error('account_snapshot', [
            'opaqueAccountId' => $dto['opaqueAccountId'],
            'status' => $dto['entitlement']['status'] ?? null,
        ]);
        return $dto;
    }

    public static function maskEmail(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '***';
        }
        $local = $parts[0];
        $visible = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);
        return $visible . '***@' . $parts[1];
    }
}
