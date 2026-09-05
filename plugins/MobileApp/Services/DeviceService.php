<?php

namespace Plugin\MobileApp\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Models\Device;
use Plugin\MobileApp\Support\MobileEnvelope;

final class DeviceService
{
    public const FORBIDDEN_FIELDS = [
        'advertisingId',
        'aaid',
        'gaid',
        'idfa',
        'imei',
        'hardwareSerial',
        'androidId',
        'macAddress',
    ];

    public function register(User $user, array $input): array
    {
        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                throw new MobileApiException('DEVICE_INVALID', 400);
            }
        }
        $opaque = trim((string) ($input['opaqueDeviceId'] ?? ''));
        if ($opaque === '' || strlen($opaque) < 8 || strlen($opaque) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $opaque)) {
            throw new MobileApiException('DEVICE_INVALID', 400);
        }
        $platform = strtolower(trim((string) ($input['platform'] ?? 'android')));
        if ($platform !== 'android') {
            throw new MobileApiException('DEVICE_INVALID', 400);
        }
        $appVersion = substr((string) ($input['appVersion'] ?? '0.0.0'), 0, 32);
        $androidApi = isset($input['androidApi']) && is_numeric($input['androidApi']) ? (int) $input['androidApi'] : null;
        $mobileApi = isset($input['mobileApiVersion']) && is_numeric($input['mobileApiVersion']) ? (int) $input['mobileApiVersion'] : 1;
        $schema = isset($input['profileSchemaVersion']) && is_numeric($input['profileSchemaVersion']) ? (int) $input['profileSchemaVersion'] : 1;
        $device = Device::query()->updateOrCreate(
            ['user_id' => $user->id, 'opaque_device_id' => $opaque],
            [
                'platform' => $platform,
                'app_version' => $appVersion,
                'android_api' => $androidApi,
                'mobile_api_version' => $mobileApi,
                'profile_schema_version' => $schema,
                'libxray_version' => substr((string) ($input['libxrayVersion'] ?? ''), 0, 64),
                'xray_core_version' => substr((string) ($input['xrayCoreVersion'] ?? ''), 0, 64),
                'last_seen_at' => Carbon::now(),
                'request_id' => MobileEnvelope::requestId(),
                'environment' => (string) (config('app.env') ?: 'testing'),
            ]
        );
        $seen = $device->last_seen_at;
        $epoch = $seen instanceof \DateTimeInterface ? $seen->getTimestamp() : time();
        return [
            'opaqueDeviceId' => (string) $device->opaque_device_id,
            'lastSeenAt' => gmdate('Y-m-d\TH:i:s\Z', $epoch),
        ];
    }
}
