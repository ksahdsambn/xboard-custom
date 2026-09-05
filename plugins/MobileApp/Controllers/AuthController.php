<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Plugin\MobileApp\Adapters\AuthAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobileAuthThrottle;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class AuthController extends PluginController
{
    public function __construct(private readonly AuthAdapter $adapter)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $this->hydrate($request);
        MobileAuthThrottle::hit('register', $request);
        $this->assertShape($request, [
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
        ]);
        $data = $this->adapter->register($request);
        return MobileEnvelope::success($data);
    }

    public function login(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $this->hydrate($request);
        MobileAuthThrottle::hit('login', $request);
        $this->adapter->assertCaptcha($request);
        $this->assertShape($request, [
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
        ], 'AUTH_CREDENTIALS_INVALID');
        $data = $this->adapter->login((string) $request->input('email'), (string) $request->input('password'));
        return MobileEnvelope::success($data);
    }

    public function emailCode(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $this->hydrate($request);
        MobileAuthThrottle::hit('email-code', $request);
        $this->assertShape($request, ['email' => 'required|email:strict'], 'AUTH_EMAIL_RESTRICTED');
        $data = $this->adapter->sendEmailCode($request);
        return MobileEnvelope::success($data);
    }

    public function passwordReset(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $this->hydrate($request);
        MobileAuthThrottle::hit('password-reset', $request);
        $this->adapter->assertCaptcha($request);
        $this->assertShape($request, [
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
            'email_code' => 'required',
        ]);
        $email = strtolower(trim((string) $request->input('email')));
        if (!User::byEmail($email)->exists()) {
            throw new MobileApiException('AUTH_EMAIL_CODE_INVALID', 400);
        }
        try {
            $data = $this->adapter->resetPassword(
                $email,
                (string) $request->input('email_code'),
                (string) $request->input('password')
            );
        } catch (MobileApiException $exception) {
            if ($exception->errorCode === 'AUTH_CREDENTIALS_INVALID') {
                throw new MobileApiException('AUTH_EMAIL_CODE_INVALID', 400);
            }
            throw $exception;
        }
        return MobileEnvelope::success($data);
    }

    public function session(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->adapter->sessionSnapshot($this->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->adapter->logoutCurrent($this->user()));
    }

    private function user(): User
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user instanceof User) {
            throw new MobileApiException('AUTH_SESSION_INVALID', 403);
        }
        return $user;
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }

    private function assertShape(Request $request, array $rules, string $code = 'AUTH_CREDENTIALS_INVALID'): void
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new MobileApiException($code, 400);
        }
    }

    private function hydrate(Request $request): void
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            $payload = $request->all();
        }
        if ($payload !== []) {
            $request->merge($payload);
        }
    }
}
