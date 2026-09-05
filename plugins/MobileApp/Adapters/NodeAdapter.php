<?php

namespace Plugin\MobileApp\Adapters;

use App\Models\User;
use App\Services\ServerService;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Services\EntitlementService;
use Plugin\MobileApp\Services\NodeFilter;
use Plugin\MobileApp\Support\MobileLogRedactor;

final class NodeAdapter
{
    public const NODE_ID_PREFIX = 'mobile-app:node:v1:';

    public const FORBIDDEN_FIELDS = [
        'userId',
        'uuid',
        'password',
        'publicKey',
        'shortId',
        'server',
        'port',
        'ports',
        'host',
        'privateKey',
        'spiderX',
        'serverName',
        'protocolSettings',
        'protocol_settings',
        'id',
        'subscribeUrl',
        'subscriptionToken',
    ];

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly NodeFilter $filter
    ) {
    }

    public static function opaqueNodeId(int|string $nodeId): string
    {
        return hash('sha256', self::NODE_ID_PREFIX . (int) $nodeId);
    }

    /**
     * @return list<array{opaqueNodeId:string,name:string,region:string,available:bool,latencyMs:int|null}>
     */
    public function listCompatible(User $user, array $clientClaims = []): array
    {
        $this->requireConnectable($user, $clientClaims);
        $authorized = $this->authorizedServers($user);
        $items = [];
        $rejected = [];
        foreach ($authorized as $server) {
            $reason = $this->filter->rejectReason($server);
            if ($reason !== null) {
                $rejected[] = $reason;
                continue;
            }
            $items[] = $this->summary($server);
        }
        MobileLogRedactor::error('nodes_list', [
            'authorizedCount' => count($authorized),
            'compatibleCount' => count($items),
            'rejectedReasons' => array_count_values($rejected),
        ]);
        return $items;
    }

    /**
     * Re-check entitlement and ServerService. Never look up by primary key.
     *
     * @return array<string, mixed>|null
     */
    public function findAuthorizedCompatible(User $user, string $opaqueNodeId, array $clientClaims = []): ?array
    {
        $this->requireConnectable($user, $clientClaims);
        foreach ($this->authorizedServers($user) as $server) {
            $candidateId = self::opaqueNodeId((int) ($server['id'] ?? 0));
            if (!hash_equals($candidateId, $opaqueNodeId)) {
                continue;
            }
            $reason = $this->filter->rejectReason($server);
            if ($reason !== null) {
                MobileLogRedactor::error('profile_rejected', ['profileRejectReason' => $reason]);
                return null;
            }
            return self::withoutServerSecrets($server);
        }
        MobileLogRedactor::error('profile_rejected', ['profileRejectReason' => 'unauthorized_or_unknown']);
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function authorizedServers(User $user): array
    {
        $servers = ServerService::getAvailableServers($user);
        return is_array($servers) ? array_values($servers) : [];
    }

    /**
     * @param array<string, mixed> $server
     * @return array{opaqueNodeId:string,name:string,region:string,available:bool,latencyMs:int|null}
     */
    public function summary(array $server): array
    {
        $item = [
            'opaqueNodeId' => self::opaqueNodeId((int) ($server['id'] ?? 0)),
            'name' => (string) ($server['name'] ?? ''),
            'region' => $this->region($server),
            'available' => (int) ($server['is_online'] ?? 0) === 1,
            'latencyMs' => $this->latencyMs($server),
        ];
        foreach (self::FORBIDDEN_FIELDS as $field) {
            unset($item[$field]);
        }
        return $item;
    }

    private function requireConnectable(User $user, array $clientClaims): void
    {
        $entitlement = $this->entitlements->forUser($user, $clientClaims);
        if (($entitlement['connectAllowed'] ?? false) === true) {
            return;
        }
        throw new MobileApiException((string) ($entitlement['denialCode'] ?? 'ENTITLEMENT_NONE'));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function region(array $server): string
    {
        $tags = $server['tags'] ?? [];
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($tags)) {
            return '';
        }
        foreach ($tags as $tag) {
            $value = trim((string) $tag);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $server
     */
    private function latencyMs(array $server): ?int
    {
        unset($server);
        return null;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public static function withoutServerSecrets(array $server): array
    {
        $settings = $server['protocol_settings'] ?? null;
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($settings)) {
            return $server;
        }
        if (isset($settings['reality_settings']) && is_array($settings['reality_settings'])) {
            unset($settings['reality_settings']['private_key'], $settings['reality_settings']['privateKey']);
        }
        if (isset($settings['encryption']) && is_array($settings['encryption'])) {
            unset($settings['encryption']['decryption']);
        }
        $server['protocol_settings'] = $settings;
        return $server;
    }
}
