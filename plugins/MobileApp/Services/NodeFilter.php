<?php

namespace Plugin\MobileApp\Services;

final class NodeFilter
{
    public const ALLOWED_PROTOCOLS = ['vless'];
    public const ALLOWED_NETWORKS = ['tcp', 'raw'];
    public const ALLOWED_FLOWS = ['xtls-rprx-vision'];
    public const ALLOWED_FINGERPRINTS = ['chrome'];
    public const REALITY_TLS = 2;

    public const REASONS = [
        'protocol_not_vless',
        'security_not_reality',
        'network_not_tcp',
        'flow_not_vision',
        'utls_disabled',
        'fingerprint_random',
        'fingerprint_unsupported',
        'allow_insecure',
        'missing_host',
        'invalid_port',
        'encryption_enabled',
        'missing_server_name',
        'missing_public_key',
        'missing_short_id',
    ];

    public function rejectReason(array $server): ?string
    {
        $type = strtolower((string) ($server['type'] ?? ''));
        if (!in_array($type, self::ALLOWED_PROTOCOLS, true)) {
            return 'protocol_not_vless';
        }

        if (trim((string) ($server['host'] ?? '')) === '') {
            return 'missing_host';
        }
        $port = $server['port'] ?? 0;
        if (!is_numeric($port) || (int) $port <= 0 || (int) $port > 65535) {
            return 'invalid_port';
        }

        $settings = $this->settings($server);
        $network = strtolower((string) ($settings['network'] ?? $server['network'] ?? ''));
        if (!in_array($network, self::ALLOWED_NETWORKS, true)) {
            return 'network_not_tcp';
        }

        $flow = strtolower((string) ($settings['flow'] ?? ''));
        if (!in_array($flow, self::ALLOWED_FLOWS, true)) {
            return 'flow_not_vision';
        }

        if ((int) ($settings['tls'] ?? 0) !== self::REALITY_TLS) {
            return 'security_not_reality';
        }

        $utls = is_array($settings['utls'] ?? null) ? $settings['utls'] : [];
        if (!$this->truthy($utls['enabled'] ?? false)) {
            return 'utls_disabled';
        }

        $fingerprint = strtolower(trim((string) ($utls['fingerprint'] ?? '')));
        if ($fingerprint === 'random') {
            return 'fingerprint_random';
        }
        if (!in_array($fingerprint, self::ALLOWED_FINGERPRINTS, true)) {
            return 'fingerprint_unsupported';
        }

        $encryption = is_array($settings['encryption'] ?? null) ? $settings['encryption'] : [];
        if ($this->truthy($encryption['enabled'] ?? false)) {
            return 'encryption_enabled';
        }

        $reality = is_array($settings['reality_settings'] ?? null) ? $settings['reality_settings'] : [];
        $tlsSettings = is_array($settings['tls_settings'] ?? null) ? $settings['tls_settings'] : [];
        if (
            $this->truthy($settings['allow_insecure'] ?? false)
            || $this->truthy($reality['allow_insecure'] ?? false)
            || $this->truthy($tlsSettings['allow_insecure'] ?? false)
        ) {
            return 'allow_insecure';
        }

        if (trim((string) ($reality['server_name'] ?? '')) === '') {
            return 'missing_server_name';
        }
        if (trim((string) ($reality['public_key'] ?? '')) === '') {
            return 'missing_public_key';
        }
        if (trim((string) ($reality['short_id'] ?? '')) === '') {
            return 'missing_short_id';
        }

        return null;
    }

    public function settings(array $server): array
    {
        $raw = $server['protocol_settings'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    private function truthy(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        return is_string($value) && strtolower($value) === 'true';
    }
}
