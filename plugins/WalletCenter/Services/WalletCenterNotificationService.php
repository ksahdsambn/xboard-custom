<?php

namespace Plugin\WalletCenter\Services;

use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;
use Plugin\WalletCenter\Models\AutoRenewRecord;

class WalletCenterNotificationService
{
    public function notifyAutoRenewFailure(User $user, AutoRenewRecord $record): void
    {
        $message = $record->reason_message ?: '余额不足，自动续费未执行。';
        $this->dispatch($user, 'auto_renew_failed', $message, [
            'record_id' => $record->id,
            'reason' => $record->reason,
            'amount' => (int) $record->amount,
        ]);
    }

    public function notifyAutoRenewSuccess(User $user, AutoRenewRecord $record): void
    {
        $snapshot = is_array($record->snapshot) ? $record->snapshot : [];
        $tradeNo = $snapshot['core_trade_no'] ?? null;
        $message = $tradeNo
            ? sprintf('自动续费已完成，订单号 %s。', $tradeNo)
            : '自动续费已完成。';

        $this->dispatch($user, 'auto_renew_success', $message, [
            'record_id' => $record->id,
            'core_trade_no' => $tradeNo,
            'amount' => (int) $record->amount,
        ]);
    }

    protected function dispatch(User $user, string $event, string $message, array $context = []): void
    {
        $payload = [
            'user' => $user,
            'event' => $event,
            'message' => $message,
            'context' => $context,
        ];

        try {
            HookManager::call('wallet_center.notify', $payload);
            HookManager::call('wallet_center.' . $event, $payload);
        } catch (\Throwable $exception) {
            Log::warning('WalletCenter notify hook failed', [
                'event' => $event,
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }

        $this->sendMail($user, $message);
        $this->sendTelegram($user, $message);
    }

    protected function sendMail(User $user, string $message): void
    {
        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return;
        }

        $jobClass = 'App\\Jobs\\SendEmailJob';
        if (!class_exists($jobClass)) {
            return;
        }

        try {
            $jobClass::dispatch([
                'email' => $email,
                'subject' => (string) admin_setting('app_name', 'Xboard') . ' 钱包通知',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => $email,
                    'content' => $message,
                    'url' => rtrim((string) config('app.url'), '/') . '/#/profile?section=renew',
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('WalletCenter mail notify failed', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function sendTelegram(User $user, string $message): void
    {
        $telegramId = $user->telegram_id ?? null;
        if (!$telegramId) {
            return;
        }

        $serviceClass = 'App\\Services\\TelegramService';
        if (!class_exists($serviceClass)) {
            return;
        }

        try {
            $service = app($serviceClass);
            if (method_exists($service, 'sendMessage')) {
                $service->sendMessage($telegramId, $message, 'markdown');
            }
        } catch (\Throwable $exception) {
            Log::warning('WalletCenter telegram notify failed', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
