<?php

namespace Plugin\MobileApp\Services;

use App\Models\User;
use Plugin\MobileApp\Adapters\NodeAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobileLogRedactor;

final class ProfileService
{
    public const SCHEMA_VERSION = 1;
    public const SPIDER_X = '/';
    public const MTU = 1280;

    public const REQUIRED_FIELDS = [
        'schemaVersion',
        'opaqueProfileId',
        'protocol',
        'security',
        'network',
        'flow',
        'server',
        'port',
        'userId',
        'serverName',
        'publicKey',
        'shortId',
        'fingerprint',
        'spiderX',
        'mtu',
        'entitlementExpiresAtEpochMs',
    ];

    public const FORBIDDEN_FIELDS = [
        'privateKey',
        'realityPrivateKey',
        'protocolSettings',
        'protocol_settings',
        'subscribeUrl',
        'subscriptionToken',
        'shareLink',
        'clashConfig',
        'singBoxConfig',
        'xrayJson',
        'id',
    ];

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly NodeAdapter $nodes,
        private readonly NodeFilter $filter
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forOpaqueId(User $user, string $opaqueProfileId, ?int $schemaVersion, array $clientClaims = []): array
    {
        if ($schemaVersion !== null && $schemaVersion !== self::SCHEMA_VERSION) {
            throw new MobileApiException('PROFILE_SCHEMA_UNSUPPORTED');
        }
        $server = $this->nodes->findAuthorizedCompatible($user, $opaqueProfileId, $clientClaims);
        if (!is_array($server)) {
            throw new MobileApiException('PROFILE_UNAVAILABLE');
        }
        $entitlement = $this->entitlements->forUser($user, $clientClaims);
        $dto = $this->assemble($server, $entitlement);
        if (!$this->isComplete($dto)) {
            MobileLogRedactor::error('profile_incomplete', ['opaqueProfileId' => $opaqueProfileId]);
            throw new MobileApiException('PROFILE_UNAVAILABLE');
        }
        MobileLogRedactor::error('profile_issued', [
            'opaqueProfileId' => $dto['opaqueProfileId'],
            'schemaVersion' => $dto['schemaVersion'],
        ]);
        return $dto;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $entitlement
     * @return array<string, mixed>
     */
    public function assemble(array $server, array $entitlement): array
    {
        $settings = $this->filter->settings($server);
        $reality = is_array($settings['reality_settings'] ?? null) ? $settings['reality_settings'] : [];
        unset($reality['private_key'], $reality['privateKey']);
        $utls = is_array($settings['utls'] ?? null) ? $settings['utls'] : [];
        $dto = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'opaqueProfileId' => NodeAdapter::opaqueNodeId((int) ($server['id'] ?? 0)),
            'protocol' => 'vless',
            'security' => 'reality',
            'network' => strtolower((string) ($settings['network'] ?? '')),
            'flow' => strtolower((string) ($settings['flow'] ?? '')),
            'server' => (string) ($server['host'] ?? ''),
            'port' => (int) ($server['port'] ?? 0),
            'userId' => (string) ($server['password'] ?? ''),
            'serverName' => (string) ($reality['server_name'] ?? ''),
            'publicKey' => (string) ($reality['public_key'] ?? ''),
            'shortId' => (string) ($reality['short_id'] ?? ''),
            'fingerprint' => strtolower(trim((string) ($utls['fingerprint'] ?? ''))),
            'spiderX' => self::SPIDER_X,
            'mtu' => self::MTU,
            'entitlementExpiresAtEpochMs' => $entitlement['expiresAtEpochMs'] ?? null,
        ];
        foreach (self::FORBIDDEN_FIELDS as $field) {
            unset($dto[$field]);
        }
        return $dto;
    }

    /**
     * @param array<string, mixed> $dto
     */
    public function isComplete(array $dto): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $dto)) {
                return false;
            }
        }
        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $dto)) {
                return false;
            }
        }
        if ((int) $dto['schemaVersion'] !== self::SCHEMA_VERSION) {
            return false;
        }
        if ($dto['protocol'] !== 'vless' || $dto['security'] !== 'reality') {
            return false;
        }
        if (!in_array($dto['network'], NodeFilter::ALLOWED_NETWORKS, true)) {
            return false;
        }
        if (!in_array($dto['flow'], NodeFilter::ALLOWED_FLOWS, true)) {
            return false;
        }
        if (!in_array($dto['fingerprint'], NodeFilter::ALLOWED_FINGERPRINTS, true)) {
            return false;
        }
        if ($dto['spiderX'] !== self::SPIDER_X || (int) $dto['mtu'] !== self::MTU) {
            return false;
        }
        if ((int) $dto['port'] <= 0) {
            return false;
        }
        foreach (['opaqueProfileId', 'server', 'userId', 'serverName', 'publicKey', 'shortId'] as $field) {
            if (trim((string) $dto[$field]) === '') {
                return false;
            }
        }
        return true;
    }
}
