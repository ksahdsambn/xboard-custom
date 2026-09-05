<?php

namespace Plugin\MobileApp\Support;

final class MobileErrorCatalog
{
    public const P0_CODES = [
        'AUTH_SESSION_INVALID',
        'ENTITLEMENT_NONE',
        'PROFILE_UNAVAILABLE',
        'PROFILE_SCHEMA_UNSUPPORTED',
        'APP_VERSION_UNSUPPORTED',
        'KERNEL_VERSION_DISABLED',
        'PURCHASE_PENDING',
        'PURCHASE_INVALID',
        'PURCHASE_DUPLICATE',
        'SERVICE_MAINTENANCE',
    ];

    public const CODES = [
        'AUTH_SESSION_INVALID' => ['http' => [401, 403], 'defaultHttp' => 403, 'reLogin' => true],
        'AUTH_CREDENTIALS_INVALID' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'AUTH_RATE_LIMITED' => ['http' => [429], 'defaultHttp' => 429, 'reLogin' => false],
        'AUTH_REGISTER_DISABLED' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'AUTH_CAPTCHA_FAILED' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'AUTH_ACCOUNT_BANNED' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'AUTH_FORBIDDEN' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'ENTITLEMENT_NONE' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'ENTITLEMENT_EXPIRED' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'ENTITLEMENT_EXHAUSTED' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'PROFILE_UNAVAILABLE' => ['http' => [403, 404], 'defaultHttp' => 403, 'reLogin' => false],
        'PROFILE_SCHEMA_UNSUPPORTED' => ['http' => [422], 'defaultHttp' => 422, 'reLogin' => false],
        'APP_VERSION_UNSUPPORTED' => ['http' => [403, 426], 'defaultHttp' => 426, 'reLogin' => false],
        'KERNEL_VERSION_DISABLED' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'PURCHASE_PENDING' => ['http' => [409], 'defaultHttp' => 409, 'reLogin' => false],
        'PURCHASE_INVALID' => ['http' => [400, 401], 'defaultHttp' => 400, 'reLogin' => false],
        'PURCHASE_DUPLICATE' => ['http' => [409], 'defaultHttp' => 409, 'reLogin' => false],
        'SERVICE_MAINTENANCE' => ['http' => [503], 'defaultHttp' => 503, 'reLogin' => false],
        'REGION_UNAVAILABLE' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'FORCE_UPGRADE' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'OPERATION_NOT_IMPLEMENTED' => ['http' => [501], 'defaultHttp' => 501, 'reLogin' => false],
        'INTERNAL_ERROR' => ['http' => [500], 'defaultHttp' => 500, 'reLogin' => false],
    ];

    public const MESSAGES = [
        'AUTH_SESSION_INVALID' => ['zh' => '未登录或登陆已过期', 'en' => 'Session expired. Please sign in again.'],
        'AUTH_CREDENTIALS_INVALID' => ['zh' => '账号或密码错误', 'en' => 'Invalid credentials.'],
        'AUTH_RATE_LIMITED' => ['zh' => '操作频繁', 'en' => 'Too many attempts.'],
        'AUTH_REGISTER_DISABLED' => ['zh' => '当前未开放注册', 'en' => 'Registration is disabled.'],
        'AUTH_CAPTCHA_FAILED' => ['zh' => '验证码校验失败', 'en' => 'Captcha verification failed.'],
        'AUTH_ACCOUNT_BANNED' => ['zh' => '账号已被封禁', 'en' => 'Account is banned.'],
        'AUTH_FORBIDDEN' => ['zh' => '当前账号无权执行该操作', 'en' => 'This account is not allowed to perform this action.'],
        'ENTITLEMENT_NONE' => ['zh' => '当前没有可连接权益', 'en' => 'No connectable entitlement.'],
        'ENTITLEMENT_EXPIRED' => ['zh' => '权益已过期', 'en' => 'Entitlement has expired.'],
        'ENTITLEMENT_EXHAUSTED' => ['zh' => '流量已耗尽', 'en' => 'Traffic quota is exhausted.'],
        'PROFILE_UNAVAILABLE' => ['zh' => '节点配置当前不可用', 'en' => 'Profile is not available.'],
        'PROFILE_SCHEMA_UNSUPPORTED' => ['zh' => '不支持的 Profile Schema', 'en' => 'Profile schema is not supported.'],
        'APP_VERSION_UNSUPPORTED' => ['zh' => '应用版本不受支持', 'en' => 'App version is not supported.'],
        'KERNEL_VERSION_DISABLED' => ['zh' => '当前内核版本已被禁用', 'en' => 'This kernel version is disabled.'],
        'PURCHASE_PENDING' => ['zh' => '购买仍在处理中', 'en' => 'Purchase is still pending.'],
        'PURCHASE_INVALID' => ['zh' => '购买无效', 'en' => 'Purchase is invalid.'],
        'PURCHASE_DUPLICATE' => ['zh' => '购买令牌已被其他账号使用', 'en' => 'Purchase token is already bound to another account.'],
        'SERVICE_MAINTENANCE' => ['zh' => '服务维护中', 'en' => 'Service is under maintenance.'],
        'REGION_UNAVAILABLE' => ['zh' => '当前区域不可用', 'en' => 'This region is unavailable.'],
        'FORCE_UPGRADE' => ['zh' => '需要升级后才能继续', 'en' => 'An upgrade is required to continue.'],
        'OPERATION_NOT_IMPLEMENTED' => ['zh' => '该能力尚未实现', 'en' => 'This operation is not implemented.'],
        'INTERNAL_ERROR' => ['zh' => '服务暂时不可用', 'en' => 'Service is temporarily unavailable.'],
    ];

    public static function exists(string $code): bool
    {
        return isset(self::CODES[$code]);
    }

    public static function allowedHttp(string $code): array
    {
        return self::CODES[$code]['http'] ?? [500];
    }

    public static function defaultHttp(string $code): int
    {
        return (int) (self::CODES[$code]['defaultHttp'] ?? 500);
    }

    public static function resolveHttp(string $code, ?int $http): int
    {
        $allowed = self::allowedHttp($code);
        if ($http !== null && in_array($http, $allowed, true)) {
            return $http;
        }
        return self::defaultHttp($code);
    }

    public static function message(string $code, string $locale): string
    {
        $locale = str_starts_with(strtolower($locale), 'en') ? 'en' : 'zh';
        return self::MESSAGES[$code][$locale] ?? self::MESSAGES['INTERNAL_ERROR'][$locale];
    }
}
