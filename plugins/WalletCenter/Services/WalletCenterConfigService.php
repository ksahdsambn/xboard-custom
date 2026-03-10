<?php

namespace Plugin\WalletCenter\Services;

use App\Models\Plugin as PluginModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Plugin\WalletCenter\Support\WalletCenterFeature;

class WalletCenterConfigService
{
    public function getPlugin(): ?PluginModel
    {
        return PluginModel::query()
            ->where('code', WalletCenterFeature::PLUGIN_CODE)
            ->first();
    }

    public function isPluginEnabled(): bool
    {
        return (bool) optional($this->getPlugin())->is_enabled;
    }

    public function getConfig(): array
    {
        return array_replace($this->getDefaultConfig(), $this->getStoredConfig());
    }

    public function getFeatureStates(): array
    {
        $states = [];
        foreach (WalletCenterFeature::all() as $feature) {
            $states[$feature] = $this->isFeatureEnabled($feature);
        }

        return $states;
    }

    public function isFeatureEnabled(string $feature): bool
    {
        $config = $this->getConfig();
        $key = WalletCenterFeature::configKey($feature);

        return $this->normalizeBoolean($config[$key] ?? false);
    }

    public function getConfigDefinition(string $key): array
    {
        $definitions = $this->getConfigDefinitions();

        return $definitions[$key] ?? [];
    }

    public function getConfigDefinitions(): array
    {
        $configPath = base_path('plugins/WalletCenter/config.json');
        if (!File::exists($configPath)) {
            return [];
        }

        $decoded = json_decode(File::get($configPath), true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded['config'] ?? [];
    }

    public function getGroupedConfigSnapshots(): array
    {
        return [
            'plugin' => $this->getConfigEntries(['display_name']),
            WalletCenterFeature::CHECKIN => $this->getFeatureConfigSnapshot(WalletCenterFeature::CHECKIN),
            WalletCenterFeature::TOPUP => $this->getFeatureConfigSnapshot(WalletCenterFeature::TOPUP),
            WalletCenterFeature::AUTO_RENEW => $this->getFeatureConfigSnapshot(WalletCenterFeature::AUTO_RENEW),
        ];
    }

    public function updateConfig(array $values): void
    {
        $plugin = $this->getPlugin();
        if (!$plugin) {
            throw new \RuntimeException('WalletCenter plugin is not installed');
        }

        $allowedKeys = array_keys($this->getConfigDefinitions());
        $merged = array_replace(
            $this->getConfig(),
            $this->sanitizeConfigValues(Arr::only($values, $allowedKeys))
        );

        $plugin->config = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $plugin->save();
    }

    public function getFeatureConfigSnapshot(string $feature): array
    {
        $definition = WalletCenterFeature::definition($feature);
        $config = $this->getConfig();
        $result = [];

        foreach ($definition['config_entries'] as $key) {
            $meta = $this->getConfigDefinition($key);
            $result[$key] = [
                'label' => $meta['label'] ?? $key,
                'type' => $meta['type'] ?? 'string',
                'value' => $config[$key] ?? ($meta['default'] ?? null),
            ];
        }

        return $result;
    }

    public function getConfigEntries(array $keys): array
    {
        $config = $this->getConfig();
        $result = [];

        foreach ($keys as $key) {
            $meta = $this->getConfigDefinition($key);
            if ($meta === []) {
                continue;
            }

            $result[$key] = [
                'label' => $meta['label'] ?? $key,
                'type' => $meta['type'] ?? 'string',
                'value' => $config[$key] ?? ($meta['default'] ?? null),
            ];
        }

        return $result;
    }

    protected function getStoredConfig(): array
    {
        $plugin = $this->getPlugin();
        if (!$plugin || empty($plugin->config)) {
            return [];
        }

        $config = is_array($plugin->config)
            ? $plugin->config
            : json_decode((string) $plugin->config, true);

        return is_array($config) ? $config : [];
    }

    protected function getDefaultConfig(): array
    {
        $defaults = [];
        foreach ($this->getConfigDefinitions() as $key => $meta) {
            $defaults[$key] = $meta['default'] ?? null;
        }

        return $defaults;
    }

    protected function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    protected function sanitizeConfigValues(array $values): array
    {
        $definitions = $this->getConfigDefinitions();
        $sanitized = [];

        foreach ($values as $key => $value) {
            $type = $definitions[$key]['type'] ?? 'string';
            $sanitized[$key] = $this->castValueByType($value, $type);
        }

        return $sanitized;
    }

    protected function castValueByType(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => $this->normalizeBoolean($value),
            'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?: []),
            default => is_scalar($value) || $value === null
                ? ($value === null ? null : (string) $value)
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
