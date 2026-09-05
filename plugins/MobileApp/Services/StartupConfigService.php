<?php

namespace Plugin\MobileApp\Services;

use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Models\CompatAudit;
use Plugin\MobileApp\Models\CompatSetting;
use Plugin\MobileApp\Support\MobileClientHints;
use Plugin\MobileApp\Support\MobileRequestId;

final class StartupConfigService
{
    public const ALLOWED_FORCE_REASONS = [
        'security-vulnerability',
        'platform-hard-requirement',
        'proven-incompatibility',
    ];

    public const FORBIDDEN_KEYS = [
        'codePayload',
        'binaryPayload',
        'script',
        'javascript',
        'wasm',
        'aar',
        'so',
        'dex',
        'privateKey',
    ];

    public const FORCE_ALLOWS = [
        'bootstrap.get',
        'legal.privacy.get',
        'legal.terms.get',
        'legal.accountDeletion.get',
        'legal.support.get',
        'account.get',
        'account.deletion.preview',
        'account.deletion.submit',
        'account.deletion.get',
    ];

    public const CONNECT_OPS = ['profiles.get'];
    public const PURCHASE_OPS = ['play.purchase.submit', 'play.purchase.restore'];

    public function settings(): CompatSetting
    {
        $row = CompatSetting::query()->orderBy('id')->first();
        if ($row !== null) {
            return $row;
        }
        return CompatSetting::query()->create([
            'maintenance' => false,
            'region_unavailable' => false,
            'blocked_regions' => [],
            'minimum_app_version' => '1.0.0',
            'suggested_app_version' => '1.0.0',
            'minimum_android_api' => 26,
            'purchase_enabled' => true,
            'connect_enabled' => true,
            'disabled_kernel_versions' => [],
            'force_upgrade_enabled' => false,
            'wallet_enabled' => false,
        ]);
    }

    public function evaluate(MobileClientHints $hints): array
    {
        $settings = $this->settings();
        $kernelDisabled = $this->kernelDisabled($hints, $settings);
        $belowMinApp = version_compare($hints->appVersion, (string) $settings->minimum_app_version, '<');
        $belowMinAndroid = $hints->androidApi !== null && $hints->androidApi < (int) $settings->minimum_android_api;
        $belowSuggested = version_compare($hints->appVersion, (string) $settings->suggested_app_version, '<');
        $regionBlocked = (bool) $settings->region_unavailable
            || in_array($hints->region, $settings->blocked_regions ?? [], true);

        $state = 'normal';
        $connectCode = null;
        if ($settings->maintenance) {
            $state = 'maintenance';
            $connectCode = 'SERVICE_MAINTENANCE';
        } elseif ($regionBlocked) {
            $state = 'region_unavailable';
            $connectCode = 'REGION_UNAVAILABLE';
        } elseif ($settings->force_upgrade_enabled) {
            $state = 'force_upgrade';
            $connectCode = 'FORCE_UPGRADE';
        } elseif ($belowMinApp || $belowMinAndroid) {
            $state = 'force_upgrade';
            $connectCode = 'APP_VERSION_UNSUPPORTED';
        } elseif ($belowSuggested) {
            $state = 'suggest_upgrade';
        }

        if ($connectCode === null) {
            if ($kernelDisabled) {
                $connectCode = 'KERNEL_VERSION_DISABLED';
            } elseif (!$settings->connect_enabled) {
                $connectCode = 'SERVICE_MAINTENANCE';
            }
        }

        $purchaseBlocked = in_array($state, ['maintenance', 'region_unavailable', 'force_upgrade'], true)
            || !$settings->purchase_enabled;
        $connectBlocked = $connectCode !== null;

        $allowed = self::FORCE_ALLOWS;
        $blocked = [];
        if ($connectBlocked) {
            $blocked = array_merge($blocked, self::CONNECT_OPS);
            $allowed = array_values(array_diff($allowed, self::CONNECT_OPS));
        }
        if ($purchaseBlocked) {
            $blocked = array_merge($blocked, self::PURCHASE_OPS);
        }
        if ($state === 'normal' || $state === 'suggest_upgrade') {
            $allowed = array_values(array_unique(array_merge($allowed, self::CONNECT_OPS, ['plans.list', 'nodes.list'])));
            if (!$purchaseBlocked) {
                $allowed = array_values(array_merge($allowed, self::PURCHASE_OPS));
            }
        }

        return [
            'startupState' => $state,
            'mobileApiVersion' => 1,
            'supportedMobileApiVersions' => [0, 1],
            'profileSchemaVersion' => 1,
            'supportedProfileSchemas' => [1],
            'maintenance' => (bool) $settings->maintenance,
            'featureFlags' => [
                'connectEnabled' => !$connectBlocked,
                'purchaseEnabled' => !$purchaseBlocked,
                'walletEnabled' => false,
            ],
            'minimumAppVersion' => (string) $settings->minimum_app_version,
            'minimumAndroidApi' => (int) $settings->minimum_android_api,
            'disabledKernelVersions' => $settings->disabled_kernel_versions ?? [],
            'allowedOperations' => array_values(array_unique($allowed)),
            'blockedOperations' => array_values(array_unique($blocked)),
            'connectErrorCode' => $connectCode,
            'purchaseBlocked' => $purchaseBlocked,
            'kernelDisabled' => $kernelDisabled,
        ];
    }

    public function assertCapability(string $capability, array $decision): void
    {
        if ($capability === 'connect' && ($decision['connectErrorCode'] ?? null)) {
            throw new MobileApiException($decision['connectErrorCode']);
        }
        if ($capability === 'purchase' && ($decision['purchaseBlocked'] ?? false)) {
            $code = $decision['connectErrorCode'] && in_array($decision['startupState'], ['maintenance', 'region_unavailable', 'force_upgrade'], true)
                ? $decision['connectErrorCode']
                : 'PURCHASE_INVALID';
            if ($decision['startupState'] === 'maintenance') {
                $code = 'SERVICE_MAINTENANCE';
            } elseif ($decision['startupState'] === 'region_unavailable') {
                $code = 'REGION_UNAVAILABLE';
            } elseif ($decision['startupState'] === 'force_upgrade') {
                $code = $decision['connectErrorCode'] ?: 'FORCE_UPGRADE';
            }
            throw new MobileApiException($code);
        }
    }

    public function bootstrapPayload(MobileClientHints $hints): array
    {
        $decision = $this->evaluate($hints);
        unset($decision['connectErrorCode'], $decision['purchaseBlocked'], $decision['kernelDisabled']);
        foreach (self::FORBIDDEN_KEYS as $key) {
            unset($decision[$key]);
        }
        return $decision;
    }

    public function update(array $input, ?int $actorId = null): CompatSetting
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                throw new MobileApiException('AUTH_FORBIDDEN', 403);
            }
        }
        $this->assertNoRemoteCode($input);

        $row = $this->settings();
        $before = $row->toArray();
        $forceSpecified = array_key_exists('forceUpgradeEnabled', $input) || array_key_exists('force_upgrade_enabled', $input);
        $forceEnabled = $forceSpecified
            ? (bool) ($input['forceUpgradeEnabled'] ?? $input['force_upgrade_enabled'])
            : (bool) $row->force_upgrade_enabled;
        $reason = (string) ($input['forceUpgradeReason'] ?? $input['force_upgrade_reason'] ?? $row->force_upgrade_reason ?? '');
        $evidence = (string) ($input['forceUpgradeEvidenceRef'] ?? $input['force_upgrade_evidence_ref'] ?? $row->force_upgrade_evidence_ref ?? '');
        $approved = (string) ($input['forceUpgradeApprovedBy'] ?? $input['force_upgrade_approved_by'] ?? $row->force_upgrade_approved_by ?? '');
        if ($forceEnabled) {
            if ($reason === 'ordinary-feature-update' || !in_array($reason, self::ALLOWED_FORCE_REASONS, true)) {
                throw new MobileApiException('AUTH_FORBIDDEN', 403);
            }
            if ($evidence === '' || $approved === '') {
                throw new MobileApiException('AUTH_FORBIDDEN', 403);
            }
        }

        $disabled = $input['disabledKernelVersions'] ?? $input['disabled_kernel_versions'] ?? $row->disabled_kernel_versions;
        $row->fill([
            'maintenance' => $input['maintenance'] ?? $row->maintenance,
            'region_unavailable' => $input['regionUnavailable'] ?? $input['region_unavailable'] ?? $row->region_unavailable,
            'blocked_regions' => $input['blockedRegions'] ?? $input['blocked_regions'] ?? $row->blocked_regions,
            'minimum_app_version' => $input['minimumAppVersion'] ?? $input['minimum_app_version'] ?? $row->minimum_app_version,
            'suggested_app_version' => $input['suggestedAppVersion'] ?? $input['suggested_app_version'] ?? $row->suggested_app_version,
            'minimum_android_api' => $input['minimumAndroidApi'] ?? $input['minimum_android_api'] ?? $row->minimum_android_api,
            'purchase_enabled' => $input['purchaseEnabled'] ?? $input['purchase_enabled'] ?? $row->purchase_enabled,
            'connect_enabled' => $input['connectEnabled'] ?? $input['connect_enabled'] ?? $row->connect_enabled,
            'disabled_kernel_versions' => $disabled,
            'force_upgrade_enabled' => $forceEnabled,
            'force_upgrade_reason' => $forceEnabled ? $reason : null,
            'force_upgrade_evidence_ref' => $forceEnabled ? $evidence : null,
            'force_upgrade_approved_by' => $forceEnabled ? $approved : null,
            'wallet_enabled' => false,
            'updated_request_id' => MobileRequestId::resolve(),
        ]);
        $row->save();

        CompatAudit::query()->create([
            'action' => 'compat.update',
            'before_json' => $before,
            'after_json' => $row->toArray(),
            'reason' => $forceEnabled ? $reason : null,
            'evidence_ref' => $forceEnabled ? $evidence : null,
            'approved_by' => $forceEnabled ? $approved : null,
            'actor_user_id' => $actorId,
            'request_id' => MobileRequestId::resolve(),
            'environment' => app()->environment() === 'testing' ? 'testing' : 'non-production',
        ]);
        return $row;
    }

    public function kernelDisabled(MobileClientHints $hints, CompatSetting $settings): bool
    {
        foreach ($settings->disabled_kernel_versions ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lib = (string) ($item['libxray'] ?? $item['libXray'] ?? '');
            $core = (string) ($item['xrayCore'] ?? $item['xray-core'] ?? '');
            if ($lib !== '' && $core !== '' && $hints->libxrayVersion === $lib && $hints->xrayCoreVersion === $core) {
                return true;
            }
        }
        return false;
    }

    public static function clientOfflineDecision(): array
    {
        return [
            'startupState' => 'offline',
            'allowedOperations' => [
                'legal.privacy.get',
                'legal.terms.get',
                'legal.accountDeletion.get',
                'legal.support.get',
            ],
            'blockedOperations' => ['profiles.get', 'play.purchase.submit'],
            'connectErrorCode' => null,
        ];
    }

    private function assertNoRemoteCode(array $input): void
    {
        $encoded = strtolower(json_encode($input) ?: '');
        foreach (['<script', 'javascript:', 'data:application', '.so"', '.aar"', 'wasm'] as $needle) {
            if (str_contains($encoded, $needle)) {
                throw new MobileApiException('AUTH_FORBIDDEN', 403);
            }
        }
    }
}
