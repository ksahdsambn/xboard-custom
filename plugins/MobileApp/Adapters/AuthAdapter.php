<?php

namespace Plugin\MobileApp\Adapters;

use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\RegisterService;
use App\Services\AuthService;
use App\Services\CaptchaService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\AuthErrorMapper;
use Plugin\MobileApp\Support\MobileLogRedactor;

final class AuthAdapter
{
    public const ACCOUNT_ID_PREFIX = 'mobile-app:account:v1:';
    public const TOKEN_TYPE = 'Bearer';
    public const SESSION_FIELDS = ['tokenType', 'sanctumToken', 'expiresAtEpochMs'];
    public const REGISTER_FIELDS = ['opaqueAccountId'];
    public const FORBIDDEN_SESSION_FIELDS = ['token', 'subscriptionToken', 'auth_data', 'privateKey', 'is_admin', 'uuid', 'userId'];

    public function __construct(
        private readonly RegisterService $registerService,
        private readonly LoginService $loginService
    ) {
    }

    public function register(Request $request): array
    {
        try {
            [$ok, $result] = $this->registerService->register($request);
        } catch (ApiException $exception) {
            throw AuthErrorMapper::toException($exception);
        }
        if (!$ok) {
            throw AuthErrorMapper::toException($result);
        }
        MobileLogRedactor::error('auth_register', ['opaqueAccountId' => self::opaqueAccountId($result)], $request);
        return ['opaqueAccountId' => self::opaqueAccountId($result)];
    }

    public function login(string $email, string $password): array
    {
        [$ok, $result] = $this->loginService->login($email, $password);
        if (!$ok) {
            throw AuthErrorMapper::toException($result);
        }
        return $this->issueSession($result);
    }

    public function resetPassword(string $email, string $emailCode, string $password): array
    {
        [$ok, $result] = $this->loginService->resetPassword($email, $emailCode, $password);
        if (!$ok) {
            throw AuthErrorMapper::toException($result);
        }
        $user = User::byEmail($email)->first();
        if ($user instanceof User) {
            (new AuthService($user))->removeAllSessions();
        }
        return ['accepted' => true];
    }

    public function issueSession(User $user): array
    {
        $raw = (new AuthService($user))->generateAuthData();
        $authData = (string) ($raw['auth_data'] ?? '');
        $plain = (string) preg_replace('/^Bearer\s+/i', '', $authData);
        if ($plain === '' || (isset($raw['token']) && hash_equals((string) $raw['token'], $plain))) {
            throw new MobileApiException('INTERNAL_ERROR', 500);
        }
        $accessToken = PersonalAccessToken::findToken($plain);
        $expires = $accessToken?->expires_at;
        $expiresAtEpochMs = $expires ? ((int) $expires->getTimestamp() * 1000) : ((time() + 365 * 24 * 3600) * 1000);
        $dto = [
            'tokenType' => self::TOKEN_TYPE,
            'sanctumToken' => $plain,
            'expiresAtEpochMs' => $expiresAtEpochMs,
        ];
        foreach (self::FORBIDDEN_SESSION_FIELDS as $field) {
            unset($dto[$field]);
        }
        MobileLogRedactor::error('auth_login', [
            'opaqueAccountId' => self::opaqueAccountId($user),
            'tokenType' => self::TOKEN_TYPE,
        ]);
        return $dto;
    }

    public static function opaqueAccountId(User $user): string
    {
        return hash('sha256', self::ACCOUNT_ID_PREFIX . $user->id);
    }

    public static function sessionUsesSubscriptionToken(array $dto, User $user): bool
    {
        $token = (string) ($dto['sanctumToken'] ?? '');
        return $token !== '' && hash_equals((string) $user->token, $token);
    }

    public function assertCaptcha(Request $request): void
    {
        [$ok, $error] = app(CaptchaService::class)->verify($request);
        if (!$ok) {
            throw AuthErrorMapper::toException($error);
        }
    }

    public function sendEmailCode(Request $request): array
    {
        $this->assertCaptcha($request);
        $email = strtolower(trim((string) $request->input('email')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MobileApiException('AUTH_EMAIL_RESTRICTED', 400);
        }
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            $registered = User::byEmail($email)->exists();
            if (!$registered) {
                $allowed = Helper::getEmailSuffix();
                $suffix = substr((string) strrchr($email, '@'), 1);
                if (!is_array($allowed) || !in_array($suffix, $allowed, true)) {
                    throw new MobileApiException('AUTH_EMAIL_RESTRICTED', 400);
                }
            }
        }
        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            throw new MobileApiException('AUTH_RATE_LIMITED', 429);
        }
        $code = random_int(100000, 999999);
        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => admin_setting('app_name', 'XBoard') . __('Email verification code'),
            'template_name' => 'verify',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'code' => $code,
                'url' => admin_setting('app_url'),
            ],
        ]);
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email), time(), 60);
        MobileLogRedactor::error('auth_email_code', ['accepted' => true], $request);
        return ['accepted' => true];
    }

    public function sessionSnapshot(User $user): array
    {
        $token = $user->currentAccessToken();
        if ($token === null) {
            throw new MobileApiException('AUTH_SESSION_INVALID', 403);
        }
        $expires = $token->expires_at;
        return [
            'valid' => true,
            'expiresAtEpochMs' => $expires ? ((int) $expires->getTimestamp() * 1000) : ((time() + 365 * 24 * 3600) * 1000),
        ];
    }

    public function logoutCurrent(User $user): array
    {
        $token = $user->currentAccessToken();
        if ($token === null) {
            throw new MobileApiException('AUTH_SESSION_INVALID', 403);
        }
        $token->delete();
        MobileLogRedactor::error('auth_logout', ['opaqueAccountId' => self::opaqueAccountId($user)]);
        return [
            'mustStopVpn' => true,
            'mustClearSensitiveData' => true,
        ];
    }
}
