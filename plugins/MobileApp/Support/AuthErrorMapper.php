<?php

namespace Plugin\MobileApp\Support;

use App\Exceptions\ApiException;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Throwable;

final class AuthErrorMapper
{
    public const KEY_TO_CODE = [
        'Incorrect email or password' => 'AUTH_CREDENTIALS_INVALID',
        'This email is not registered in the system' => 'AUTH_CREDENTIALS_INVALID',
        'Your account has been suspended' => 'AUTH_ACCOUNT_BANNED',
        'Registration has closed' => 'AUTH_REGISTER_DISABLED',
        'Invalid code is incorrect' => 'AUTH_CAPTCHA_FAILED',
        'Invalid captcha type' => 'AUTH_CAPTCHA_FAILED',
        'Email suffix is not in the Whitelist' => 'AUTH_EMAIL_RESTRICTED',
        'Email suffix is not in whitelist' => 'AUTH_EMAIL_RESTRICTED',
        'Gmail alias is not supported' => 'AUTH_EMAIL_RESTRICTED',
        'You must use the invitation code to register' => 'AUTH_INVITE_REQUIRED',
        'Invalid invitation code' => 'AUTH_INVITE_INVALID',
        'Email verification code cannot be empty' => 'AUTH_EMAIL_CODE_INVALID',
        'Incorrect email verification code' => 'AUTH_EMAIL_CODE_INVALID',
        'Email already exists' => 'AUTH_EMAIL_EXISTS',
        'Reset failed, Please try again later' => 'AUTH_RATE_LIMITED',
        'Email verification code has been sent, please request again later' => 'AUTH_RATE_LIMITED',
        'There are too many password errors, please try again after :minute minutes.' => 'AUTH_RATE_LIMITED',
        'Register frequently, please try again after :minute minute' => 'AUTH_RATE_LIMITED',
        'Reset failed' => 'INTERNAL_ERROR',
        'Register failed' => 'INTERNAL_ERROR',
    ];

    public const EXTRA_FINGERPRINTS = [
        'AUTH_CREDENTIALS_INVALID' => ['邮箱或密码错误', '郵箱或密碼錯誤', '该邮箱不存在系统中'],
        'AUTH_ACCOUNT_BANNED' => ['该账户已被停止使用', '該賬戶已被停止使用'],
        'AUTH_REGISTER_DISABLED' => ['本站已关闭注册', '本站已關閉註冊'],
        'AUTH_CAPTCHA_FAILED' => ['验证码有误', '驗證碼有誤'],
        'AUTH_EMAIL_RESTRICTED' => ['邮箱后缀不处于白名单中', '邮箱后缀不在白名单中', '不支持 Gmail 别名邮箱'],
        'AUTH_INVITE_REQUIRED' => ['必须使用邀请码才可以注册', '必須使用邀請碼才可以註冊'],
        'AUTH_INVITE_INVALID' => ['邀请码无效', '邀請碼無效'],
        'AUTH_EMAIL_EXISTS' => ['邮箱已在系统中存在', '郵箱已在系統中存在'],
        'AUTH_EMAIL_CODE_INVALID' => ['邮箱验证码有误', '邮箱验证码不能为空', '郵箱驗證碼有誤'],
        'AUTH_RATE_LIMITED' => ['密码错误次数过多', '注册频繁', '重置失败，请稍后再试', '验证码已发送'],
    ];

    public static function toException(mixed $error): MobileApiException
    {
        if ($error instanceof MobileApiException) {
            return $error;
        }
        if ($error instanceof ApiException) {
            return self::fromMessage((string) $error->getMessage(), (int) $error->getCode());
        }
        if ($error instanceof Throwable) {
            return new MobileApiException('INTERNAL_ERROR', 500);
        }
        if (!is_array($error) || !array_key_exists(0, $error)) {
            return new MobileApiException('INTERNAL_ERROR', 500);
        }
        $raw = $error[0];
        $message = (string) ($error[1] ?? '');
        $httpHint = self::httpHint($raw);
        return self::fromMessage($message, $httpHint, $raw);
    }

    public static function fromMessage(string $message, int $httpHint = 400, mixed $raw = null): MobileApiException
    {
        if ($raw === 400201) {
            return new MobileApiException('AUTH_EMAIL_EXISTS', 409);
        }
        if ($httpHint === 429) {
            return new MobileApiException('AUTH_RATE_LIMITED', 429);
        }
        $code = self::codeFromMessage($message);
        if ($code === null && self::looksLikeRateLimit($message)) {
            $code = 'AUTH_RATE_LIMITED';
        }
        if ($code === null) {
            $code = 'INTERNAL_ERROR';
        }
        $http = MobileErrorCatalog::defaultHttp($code);
        return new MobileApiException($code, $http);
    }

    public static function codeFromMessage(string $message): ?string
    {
        $actual = trim($message);
        if ($actual === '') {
            return null;
        }
        foreach (self::KEY_TO_CODE as $key => $code) {
            if (self::matchesKey($actual, $key)) {
                return $code;
            }
        }
        foreach (self::EXTRA_FINGERPRINTS as $code => $prints) {
            foreach ($prints as $print) {
                if ($actual === $print || str_contains($actual, $print)) {
                    return $code;
                }
            }
        }
        return null;
    }

    private static function matchesKey(string $actual, string $key): bool
    {
        if ($actual === $key) {
            return true;
        }
        try {
            $translated = trim((string) __($key));
            if ($translated !== '' && $actual === $translated) {
                return true;
            }
            if (str_contains($key, ':')) {
                $stripped = trim((string) preg_replace('/:[A-Za-z_]+/', '', $translated !== '' ? $translated : $key));
                $stripped = trim((string) preg_replace('/\s+/', ' ', $stripped));
                if ($stripped !== '' && str_contains($actual, explode(' ', $stripped)[0])) {
                    return true;
                }
            }
        } catch (Throwable) {
            return $actual === $key;
        }
        return false;
    }

    private static function looksLikeRateLimit(string $message): bool
    {
        $haystack = mb_strtolower($message);
        foreach (['too many password', 'register frequently', '密码错误次数过多', '注册频繁', '验证码已发送', '重置失败，请稍后再试'] as $fragment) {
            if (str_contains($haystack, mb_strtolower($fragment))) {
                return true;
            }
        }
        return false;
    }

    private static function httpHint(mixed $raw): int
    {
        if (!is_int($raw) && !is_numeric($raw)) {
            return 400;
        }
        $code = (int) $raw;
        if ($code >= 100000) {
            return (int) substr((string) $code, 0, 3);
        }
        if ($code >= 100 && $code < 600) {
            return $code;
        }
        return 400;
    }
}
