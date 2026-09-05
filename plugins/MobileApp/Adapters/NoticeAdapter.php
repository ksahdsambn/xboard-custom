<?php

namespace Plugin\MobileApp\Adapters;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Models\NoticeRead;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\MobilePaginator;
use Plugin\MobileApp\Support\NoticeHtmlSanitizer;

final class NoticeAdapter
{
    public const FORBIDDEN_FIELDS = [
        'img_url',
        'imgUrl',
        'tags',
        'show',
        'content',
        'privateKey',
        'subscriptionToken',
        'user_id',
        'userId',
    ];

    public static function opaqueNoticeId(int $noticeId): string
    {
        return substr(hash('sha256', 'mobile-notice:' . $noticeId), 0, 32);
    }

    public function list(User $user, int $page, int $perPage): array
    {
        [$page, $perPage] = MobilePaginator::normalize($page, $perPage);
        $query = Notice::query()->where('show', true)->orderBy('sort', 'ASC')->orderBy('id', 'DESC');
        $total = (int) $query->count();
        $rows = $query->forPage($page, $perPage)->get();
        $readIds = $this->readNoticeIds($user, $rows->pluck('id')->all());
        $items = [];
        foreach ($rows as $notice) {
            $items[] = $this->summaryDto($notice, in_array((int) $notice->id, $readIds, true));
        }
        return MobilePaginator::payload($items, $page, $perPage, $total);
    }

    public function detail(User $user, string $opaqueNoticeId): array
    {
        $notice = $this->visibleByOpaqueId($opaqueNoticeId);
        $read = NoticeRead::query()
            ->where('user_id', $user->id)
            ->where('notice_id', $notice->id)
            ->exists();
        return $this->detailDto($notice, $read);
    }

    public function markRead(User $user, string $opaqueNoticeId): array
    {
        $notice = $this->visibleByOpaqueId($opaqueNoticeId);
        NoticeRead::query()->updateOrCreate(
            ['user_id' => $user->id, 'notice_id' => $notice->id],
            [
                'read_at' => Carbon::now(),
                'request_id' => MobileEnvelope::requestId(),
                'environment' => (string) (config('app.env') ?: 'testing'),
            ]
        );
        return ['read' => true];
    }

    private function visibleByOpaqueId(string $opaqueNoticeId): Notice
    {
        $notices = Notice::query()->where('show', true)->get();
        foreach ($notices as $notice) {
            $candidate = self::opaqueNoticeId((int) $notice->id);
            if (hash_equals($candidate, $opaqueNoticeId)) {
                return $notice;
            }
        }
        throw new MobileApiException('NOTICE_NOT_FOUND', 404);
    }

    private function readNoticeIds(User $user, array $noticeIds): array
    {
        if ($noticeIds === []) {
            return [];
        }
        return NoticeRead::query()
            ->where('user_id', $user->id)
            ->whereIn('notice_id', $noticeIds)
            ->pluck('notice_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function summaryDto(Notice $notice, bool $read): array
    {
        return [
            'id' => self::opaqueNoticeId((int) $notice->id),
            'title' => (string) $notice->title,
            'publishedAt' => $this->publishedAt($notice),
            'read' => $read,
        ];
    }

    private function detailDto(Notice $notice, bool $read): array
    {
        return [
            'id' => self::opaqueNoticeId((int) $notice->id),
            'title' => (string) $notice->title,
            'body' => NoticeHtmlSanitizer::sanitize((string) $notice->content),
            'publishedAt' => $this->publishedAt($notice),
            'read' => $read,
        ];
    }

    private function publishedAt(Notice $notice): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->epoch($notice->created_at));
    }

    private function epoch(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return max(0, $value->getTimestamp());
        }
        return max(0, (int) ($value ?: 0));
    }
}
