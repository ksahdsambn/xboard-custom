<?php

namespace Plugin\MobileApp\Services;

use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Plugin\MobileApp\Adapters\PlanAdapter;
use Plugin\MobileApp\Adapters\PlayDeveloperAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Models\PurchaseToken;
use Plugin\MobileApp\Models\RtdnEvent;
use Plugin\MobileApp\Support\MobileLogRedactor;
use Plugin\MobileApp\Support\MobilePaginator;
use Plugin\MobileApp\Support\MobileRequestId;

final class RtdnService
{
    public const PLATFORM = 'google_play';

    public const MAX_RETRIES = 5;

    public const BACKOFF_SECONDS = [60, 120, 240, 480, 960];

    public function __construct(
        private readonly PlayDeveloperAdapter $developer,
        private readonly PlayPurchaseService $purchases
    ) {
    }

    public static function make(): self
    {
        return new self(
            PlayDeveloperAdapter::shared(),
            new PlayPurchaseService(PlayDeveloperAdapter::shared(), new PlanService(new Plan()))
        );
    }

    public function handle(array $payload, string $rawBody): array
    {
        $message = $payload['message'] ?? null;
        if (!is_array($message) || trim((string) ($message['messageId'] ?? '')) === '') {
            throw new MobileApiException('PURCHASE_INVALID', 400);
        }
        $eventId = trim((string) $message['messageId']);
        $attributes = is_array($message['attributes'] ?? null) ? $message['attributes'] : [];
        $environment = trim((string) ($attributes['environment'] ?? ''));
        $expectedEnv = PlanAdapter::playEnvironment();
        $decoded = $this->decodeData((string) ($message['data'] ?? ''));
        $package = (string) ($decoded['packageName'] ?? '');
        $eventTime = isset($decoded['eventTimeMillis']) ? (int) $decoded['eventTimeMillis'] : null;
        $sub = is_array($decoded['subscriptionNotification'] ?? null)
            ? $decoded['subscriptionNotification']
            : (is_array($decoded['voidedPurchaseNotification'] ?? null) ? $decoded['voidedPurchaseNotification'] : null);
        $claimedType = is_array($sub) && isset($sub['notificationType']) ? (int) $sub['notificationType'] : null;
        $token = is_array($sub) ? (string) ($sub['purchaseToken'] ?? '') : '';
        $isTest = isset($decoded['testNotification']);
        $requestId = $this->requestId();

        if ($environment !== $expectedEnv || ($package !== '' && $package !== PlayDeveloperAdapter::PACKAGE)) {
            $existing = RtdnEvent::query()
                ->where('platform', self::PLATFORM)
                ->where('event_id', $eventId)
                ->first();
            if ($existing instanceof RtdnEvent) {
                return [
                    'accepted' => true,
                    'duplicate' => true,
                    'processingStatus' => $existing->processing_status,
                ];
            }
            $this->persistRejected($eventId, $environment !== '' ? $environment : $expectedEnv, $rawBody, $requestId, 'ENV_MISMATCH');
            throw new MobileApiException('PURCHASE_INVALID', 400);
        }

        try {
            $result = DB::transaction(function () use ($eventId, $expectedEnv, $rawBody, $eventTime, $claimedType, $token, $isTest, $requestId): array {
                $existing = RtdnEvent::query()
                    ->where('platform', self::PLATFORM)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof RtdnEvent) {
                    return [
                        'accepted' => true,
                        'duplicate' => true,
                        'processingStatus' => $existing->processing_status,
                    ];
                }
                $row = RtdnEvent::query()->create([
                    'platform' => self::PLATFORM,
                    'event_id' => $eventId,
                    'environment' => $expectedEnv,
                    'payload_digest' => hash('sha256', $rawBody),
                    'processing_status' => 'received',
                    'request_id' => $requestId,
                    'retry_count' => 0,
                    'received_at' => now(),
                    'purchase_token_hash' => $token !== '' ? hash('sha256', $token) : null,
                    'event_time_millis' => $eventTime,
                    'claimed_notification_type' => $claimedType,
                    'apply_count' => 0,
                ]);
                if ($isTest && $token === '') {
                    $row->processing_status = 'processed';
                    $row->processed_at = now();
                    $row->save();
                    return ['accepted' => true, 'duplicate' => false];
                }
                if ($token === '') {
                    $row->processing_status = 'rejected';
                    $row->last_error = 'PURCHASE_INVALID';
                    $row->save();
                    return ['errorCode' => 'PURCHASE_INVALID'];
                }
                $this->recheckAndApply($row, $token);
                return ['accepted' => true, 'duplicate' => false];
            });
            if (($result['errorCode'] ?? null) === 'PURCHASE_INVALID') {
                throw new MobileApiException('PURCHASE_INVALID', 400);
            }
            return $result;
        } catch (UniqueConstraintViolationException|\Illuminate\Database\QueryException $exception) {
            if ($exception instanceof \Illuminate\Database\QueryException
                && !($exception instanceof UniqueConstraintViolationException)
            ) {
                $messageText = $exception->getMessage();
                if (!str_contains($messageText, 'UNIQUE') && !str_contains(strtolower($messageText), 'unique') && (string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
            $existing = RtdnEvent::query()
                ->where('platform', self::PLATFORM)
                ->where('event_id', $eventId)
                ->first();
            return [
                'accepted' => true,
                'duplicate' => true,
                'processingStatus' => $existing?->processing_status ?? 'received',
            ];
        }
    }

    public function processDueRetries(?int $now = null): int
    {
        $nowTs = $now ?? time();
        $dueAt = date('Y-m-d H:i:s', $nowTs);
        $due = RtdnEvent::query()
            ->where('processing_status', 'retry')
            ->where(function ($query) use ($dueAt): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $dueAt);
            })
            ->orderBy('id')
            ->get();
        $processed = 0;
        foreach ($due as $row) {
            DB::transaction(function () use ($row, &$processed): void {
                $locked = RtdnEvent::query()->whereKey($row->id)->lockForUpdate()->first();
                if (!$locked instanceof RtdnEvent || $locked->processing_status !== 'retry') {
                    return;
                }
                $this->recheckAndApply($locked, '');
                $processed++;
            });
        }
        return $processed;
    }

    public function auditView(int $page = 1, int $perPage = 20): array
    {
        [$page, $perPage] = MobilePaginator::normalize($page, $perPage);
        $total = RtdnEvent::query()->count();
        $rows = RtdnEvent::query()->orderByDesc('id')->forPage($page, $perPage)->get();
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'eventId' => $row->event_id,
                'environment' => $row->environment,
                'processingStatus' => $row->processing_status,
                'retryCount' => (int) $row->retry_count,
                'lastError' => $row->last_error,
                'playStatusApplied' => $row->play_status_applied,
                'applyCount' => (int) $row->apply_count,
                'receivedAt' => $row->received_at?->toIso8601String(),
                'processedAt' => $row->processed_at?->toIso8601String(),
            ];
        }
        return MobilePaginator::payload($items, $page, $perPage, $total);
    }

    private function recheckAndApply(RtdnEvent $row, string $token): void
    {
        // claimed notificationType / playStatus / entitlement from the body are ignored
        try {
            $snapshot = $token !== ''
                ? $this->developer->getSubscription(PlayDeveloperAdapter::PACKAGE, $token)
                : $this->developer->getSubscriptionByHash((string) $row->purchase_token_hash);
        } catch (\Throwable $exception) {
            $this->queueRetry($row, 'DEVELOPER_API_UNAVAILABLE');
            return;
        }
        if ($snapshot === null
            || ($snapshot['packageName'] ?? '') !== PlayDeveloperAdapter::PACKAGE
            || ($snapshot['evidenceClass'] ?? '') === 'production'
        ) {
            $row->processing_status = 'rejected';
            $row->last_error = 'PURCHASE_INVALID';
            $row->processed_at = now();
            $row->save();
            return;
        }
        $hash = (string) $row->purchase_token_hash;
        if ($hash === '' && $token !== '') {
            $hash = hash('sha256', $token);
            $row->purchase_token_hash = $hash;
        }
        $ledger = $this->purchases->findByTokenHash($hash);
        $status = (string) ($snapshot['playStatus'] ?? '');
        if ($ledger instanceof PurchaseToken) {
            $applied = $this->purchases->applyRecheckedSnapshot($ledger, $snapshot);
            $row->play_status_applied = $applied['playStatus'];
            $row->applied_digest = $applied['digest'];
            if ($applied['changed']) {
                $row->apply_count = (int) $row->apply_count + 1;
            }
        } else {
            $row->play_status_applied = $status;
            $row->applied_digest = hash('sha256', $hash . '|' . $status . '|' . (string) ($snapshot['expiryTime'] ?? ''));
        }
        $row->processing_status = 'processed';
        $row->last_error = null;
        $row->processed_at = now();
        $row->next_retry_at = null;
        $row->save();
        MobileLogRedactor::error('rtdn_processed', [
            'eventId' => $row->event_id,
            'playStatus' => $row->play_status_applied,
            'applyCount' => (int) $row->apply_count,
            'tokenHashPrefix' => substr($hash, 0, 8),
        ]);
    }

    private function queueRetry(RtdnEvent $row, string $error): void
    {
        $count = (int) $row->retry_count + 1;
        $row->retry_count = $count;
        $row->last_error = $error;
        if ($count > self::MAX_RETRIES) {
            $row->processing_status = 'dead_letter';
            $row->next_retry_at = null;
            $row->processed_at = now();
        } else {
            $row->processing_status = 'retry';
            $delay = self::BACKOFF_SECONDS[min($count - 1, count(self::BACKOFF_SECONDS) - 1)];
            $row->next_retry_at = now()->addSeconds($delay);
        }
        $row->save();
        MobileLogRedactor::error('rtdn_retry', [
            'eventId' => $row->event_id,
            'retryCount' => $count,
            'status' => $row->processing_status,
        ]);
    }

    private function persistRejected(string $eventId, string $environment, string $rawBody, string $requestId, string $error): void
    {
        try {
            RtdnEvent::query()->create([
                'platform' => self::PLATFORM,
                'event_id' => $eventId,
                'environment' => $environment,
                'payload_digest' => hash('sha256', $rawBody),
                'processing_status' => 'rejected',
                'request_id' => $requestId,
                'last_error' => $error,
                'retry_count' => 0,
                'received_at' => now(),
                'processed_at' => now(),
                'apply_count' => 0,
            ]);
        } catch (\Throwable) {
            // duplicate rejected event is still a rejection
        }
    }

    private function decodeData(string $data): array
    {
        if ($data === '') {
            return [];
        }
        $json = base64_decode($data, true);
        if ($json === false) {
            $json = $data;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function requestId(): string
    {
        try {
            return MobileRequestId::resolve();
        } catch (\Throwable) {
            return (string) \Illuminate\Support\Str::uuid();
        }
    }
}
