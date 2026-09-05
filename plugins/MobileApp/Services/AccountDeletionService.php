<?php

namespace Plugin\MobileApp\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Models\AccountLink;
use Plugin\MobileApp\Models\DeletionRequest;
use Plugin\MobileApp\Models\Device;
use Plugin\MobileApp\Models\NoticeRead;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\MobileLogRedactor;

final class AccountDeletionService
{
    public const STATUS_NONE = 'none';
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXECUTED = 'executed';
    public const RETENTION_YEARS = 7;
    public const ANON_TEXT = '[account-deleted]';
    public const ANON_EMAIL_DOMAIN = 'invalid.account';

    public function preview(User $user): array
    {
        $legal = (new LegalDocumentService())->accountDeletion();
        $warning = (string) ($legal['playSubscriptionWarning'] ?? 'Deleting the Xboard account does not cancel Play subscriptions.');
        $managementUrl = (string) ($legal['playSubscriptionManagementUrl'] ?? 'https://play.google.com/store/account/subscriptions');
        if ($this->executedRequest($user) instanceof DeletionRequest) {
            MobileLogRedactor::error('deletion_preview', ['status' => self::STATUS_EXECUTED, 'opaqueAccountId' => hash('sha256', 'mobile-app:account:v1:' . $user->id)]);
            return [
                'playSubscriptionWarning' => $warning,
                'requiresConfirmation' => false,
                'playSubscriptionManagementUrl' => $managementUrl,
                'status' => self::STATUS_EXECUTED,
            ];
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $pending = DeletionRequest::query()
            ->where('user_id', $user->id)
            ->where('status', self::STATUS_PENDING)
            ->first();
        $fields = [
            'user_id' => $user->id,
            'status' => self::STATUS_PENDING,
            'confirmation_token_hash' => $hash,
            'play_subscription_warning_ack' => false,
            'request_id' => MobileEnvelope::requestId(),
            'environment' => (string) (config('app.env') ?: 'testing'),
            'retain_until' => null,
            'executed_at' => null,
            'last_error' => null,
        ];
        if ($pending instanceof DeletionRequest) {
            $pending->fill($fields);
            $pending->save();
        } else {
            DeletionRequest::query()->create($fields);
        }
        MobileLogRedactor::error('deletion_preview', ['status' => self::STATUS_PENDING, 'opaqueAccountId' => hash('sha256', 'mobile-app:account:v1:' . $user->id)]);
        return [
            'playSubscriptionWarning' => $warning,
            'requiresConfirmation' => true,
            'confirmationToken' => $token,
            'playSubscriptionManagementUrl' => $managementUrl,
            'status' => self::STATUS_PENDING,
        ];
    }

    public function status(User $user): array
    {
        if ($this->executedRequest($user) instanceof DeletionRequest) {
            return ['status' => self::STATUS_EXECUTED];
        }
        $pending = DeletionRequest::query()
            ->where('user_id', $user->id)
            ->where('status', self::STATUS_PENDING)
            ->exists();
        return ['status' => $pending ? self::STATUS_PENDING : self::STATUS_NONE];
    }

    public function execute(User $user, array $input): array
    {
        $dto = $this->executedDto();
        DB::transaction(function () use ($user, $input, &$dto): void {
            $fresh = User::query()->where('id', $user->id)->lockForUpdate()->first();
            if (!$fresh instanceof User) {
                throw new MobileApiException('AUTH_SESSION_INVALID', 403);
            }
            $executed = $this->executedRequest($fresh);
            if ($executed instanceof DeletionRequest) {
                $dto = $this->executedDto();
                return;
            }
            $this->assertConfirmation($fresh, $input);
            $this->anonymizeUser($fresh);
            $this->purgePersonalPluginData($fresh);
            $this->anonymizeTickets($fresh);
            $this->markExecuted($fresh);
            $dto = $this->executedDto();
        });
        (new AuthService($user))->removeAllSessions();
        MobileLogRedactor::error('deletion_execute', ['status' => self::STATUS_EXECUTED]);
        return $dto;
    }

    private function assertConfirmation(User $user, array $input): void
    {
        $ack = $input['playSubscriptionWarningAck'] ?? false;
        if ($ack !== true && $ack !== 1 && $ack !== '1' && $ack !== 'true') {
            throw new MobileApiException('DELETION_PLAY_WARNING_REQUIRED', 400);
        }
        $token = (string) ($input['confirmationToken'] ?? '');
        $pending = DeletionRequest::query()
            ->where('user_id', $user->id)
            ->where('status', self::STATUS_PENDING)
            ->first();
        if (!$pending instanceof DeletionRequest || $token === '' || !hash_equals((string) $pending->confirmation_token_hash, hash('sha256', $token))) {
            throw new MobileApiException('DELETION_CONFIRMATION_INVALID', 400);
        }
        $password = (string) ($input['password'] ?? '');
        if ($password === '' || !Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, (string) $user->password)) {
            throw new MobileApiException('AUTH_CREDENTIALS_INVALID', 400);
        }
    }

    private function anonymizeUser(User $user): void
    {
        $digest = substr(hash('sha256', 'mobile-app:deleted:v1:' . $user->id), 0, 16);
        $user->email = 'deleted.' . $digest . '@' . self::ANON_EMAIL_DOMAIN;
        $user->password = Hash::make(bin2hex(random_bytes(32)));
        $user->password_algo = null;
        $user->password_salt = null;
        $user->banned = true;
        $user->telegram_id = null;
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->plan_id = null;
        $user->group_id = null;
        $user->expired_at = 0;
        $user->transfer_enable = 0;
        $user->save();
    }

    private function purgePersonalPluginData(User $user): void
    {
        Device::query()->where('user_id', $user->id)->delete();
        NoticeRead::query()->where('user_id', $user->id)->delete();
        AccountLink::query()->where('user_id', $user->id)->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);
    }

    private function anonymizeTickets(User $user): void
    {
        $tickets = Ticket::query()->where('user_id', $user->id)->get();
        foreach ($tickets as $ticket) {
            $ticket->subject = self::ANON_TEXT;
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->save();
            TicketMessage::query()->where('ticket_id', $ticket->id)->update(['message' => self::ANON_TEXT]);
        }
    }

    private function markExecuted(User $user): void
    {
        $pending = DeletionRequest::query()
            ->where('user_id', $user->id)
            ->where('status', self::STATUS_PENDING)
            ->first();
        if (!$pending instanceof DeletionRequest) {
            throw new MobileApiException('DELETION_CONFIRMATION_INVALID', 400);
        }
        $pending->status = self::STATUS_EXECUTED;
        $pending->play_subscription_warning_ack = true;
        $pending->executed_at = now();
        $pending->retain_until = now()->addYears(self::RETENTION_YEARS);
        $pending->request_id = MobileEnvelope::requestId();
        $pending->last_error = null;
        $pending->save();
    }

    private function executedRequest(User $user): ?DeletionRequest
    {
        $row = DeletionRequest::query()
            ->where('user_id', $user->id)
            ->where('status', self::STATUS_EXECUTED)
            ->first();
        return $row instanceof DeletionRequest ? $row : null;
    }

    private function executedDto(): array
    {
        return [
            'status' => self::STATUS_EXECUTED,
            'mustStopVpn' => true,
            'mustClearSensitiveData' => true,
        ];
    }
}
