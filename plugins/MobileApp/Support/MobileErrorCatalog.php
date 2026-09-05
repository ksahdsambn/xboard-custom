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
        'AUTH_EMAIL_RESTRICTED' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'AUTH_INVITE_REQUIRED' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'AUTH_INVITE_INVALID' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'AUTH_EMAIL_EXISTS' => ['http' => [409], 'defaultHttp' => 409, 'reLogin' => false],
        'AUTH_EMAIL_CODE_INVALID' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
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
        'NOTICE_NOT_FOUND' => ['http' => [404], 'defaultHttp' => 404, 'reLogin' => false],
        'TICKET_NOT_FOUND' => ['http' => [404], 'defaultHttp' => 404, 'reLogin' => false],
        'TICKET_CLOSED' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'TICKET_WAIT_REPLY' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'TICKET_EMPTY' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'TICKET_OPEN_EXISTS' => ['http' => [409], 'defaultHttp' => 409, 'reLogin' => false],
        'TICKET_ALREADY_CLOSED' => ['http' => [409], 'defaultHttp' => 409, 'reLogin' => false],
        'DEVICE_INVALID' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'DELETION_CONFIRMATION_INVALID' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'DELETION_PLAY_WARNING_REQUIRED' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'PLAY_PRODUCT_INVALID' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'PLAY_PRODUCT_DUPLICATE' => ['http' => [409], 'defaultHttp' => 409, 'reLogin' => false],
        'INTERNAL_ERROR' => ['http' => [500], 'defaultHttp' => 500, 'reLogin' => false],
        'HTTPS_REQUIRED' => ['http' => [403], 'defaultHttp' => 403, 'reLogin' => false],
        'REQUEST_TOO_LARGE' => ['http' => [413], 'defaultHttp' => 413, 'reLogin' => false],
        'PAGINATION_INVALID' => ['http' => [400], 'defaultHttp' => 400, 'reLogin' => false],
        'DOWNSTREAM_UNAVAILABLE' => ['http' => [503], 'defaultHttp' => 503, 'reLogin' => false],
    ];

    public const MESSAGES = [
        'AUTH_SESSION_INVALID' => ['zh' => '未登录或登陆已过期', 'en' => 'Session expired. Please sign in again.'],
        'AUTH_CREDENTIALS_INVALID' => ['zh' => '账号或密码错误', 'en' => 'Invalid credentials.'],
        'AUTH_RATE_LIMITED' => ['zh' => '操作频繁', 'en' => 'Too many attempts.'],
        'AUTH_REGISTER_DISABLED' => ['zh' => '当前未开放注册', 'en' => 'Registration is disabled.'],
        'AUTH_CAPTCHA_FAILED' => ['zh' => '验证码校验失败', 'en' => 'Captcha verification failed.'],
        'AUTH_ACCOUNT_BANNED' => ['zh' => '账号已被封禁', 'en' => 'Account is banned.'],
        'AUTH_FORBIDDEN' => ['zh' => '当前账号无权执行该操作', 'en' => 'This account is not allowed to perform this action.'],
        'AUTH_EMAIL_RESTRICTED' => ['zh' => '当前邮箱不受支持', 'en' => 'This email address is not allowed.'],
        'AUTH_INVITE_REQUIRED' => ['zh' => '注册需要邀请码', 'en' => 'An invitation code is required to register.'],
        'AUTH_INVITE_INVALID' => ['zh' => '邀请码无效', 'en' => 'Invitation code is invalid.'],
        'AUTH_EMAIL_EXISTS' => ['zh' => '该邮箱已注册', 'en' => 'This email is already registered.'],
        'AUTH_EMAIL_CODE_INVALID' => ['zh' => '邮箱验证码不正确', 'en' => 'Email verification code is incorrect.'],
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
        'NOTICE_NOT_FOUND' => ['zh' => '公告不存在', 'en' => 'Notice was not found.'],
        'TICKET_NOT_FOUND' => ['zh' => '工单不存在', 'en' => 'Ticket was not found.'],
        'TICKET_CLOSED' => ['zh' => '工单已关闭，无法回复', 'en' => 'The ticket is closed and cannot be replied.'],
        'TICKET_WAIT_REPLY' => ['zh' => '请等待客服回复', 'en' => 'Please wait for a staff reply.'],
        'TICKET_EMPTY' => ['zh' => '工单内容不能为空', 'en' => 'Ticket content cannot be empty.'],
        'TICKET_OPEN_EXISTS' => ['zh' => '存在未关闭的工单', 'en' => 'An open ticket already exists.'],
        'TICKET_ALREADY_CLOSED' => ['zh' => '工单已关闭', 'en' => 'Ticket is already closed.'],
        'DEVICE_INVALID' => ['zh' => '设备登记信息不被接受', 'en' => 'Device registration payload is invalid.'],
        'DELETION_CONFIRMATION_INVALID' => ['zh' => '删除确认无效', 'en' => 'Account deletion confirmation is invalid.'],
        'DELETION_PLAY_WARNING_REQUIRED' => ['zh' => '必须确认 Play 订阅不会因删号自动取消', 'en' => 'You must acknowledge that Play subscriptions are not cancelled by deleting the Xboard account.'],
        'PLAY_PRODUCT_INVALID' => ['zh' => 'Play 商品映射无效', 'en' => 'Play product mapping is invalid.'],
        'PLAY_PRODUCT_DUPLICATE' => ['zh' => '该 Play 商品已在当前环境映射', 'en' => 'This Play product is already mapped in the current environment.'],
        'INTERNAL_ERROR' => ['zh' => '服务暂时不可用', 'en' => 'Service is temporarily unavailable.'],
        'HTTPS_REQUIRED' => ['zh' => '必须使用 HTTPS', 'en' => 'HTTPS is required.'],
        'REQUEST_TOO_LARGE' => ['zh' => '请求过大', 'en' => 'Request is too large.'],
        'PAGINATION_INVALID' => ['zh' => '分页参数无效', 'en' => 'Pagination is invalid.'],
        'DOWNSTREAM_UNAVAILABLE' => ['zh' => '下游服务暂时不可用', 'en' => 'Downstream service is temporarily unavailable.'],
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
