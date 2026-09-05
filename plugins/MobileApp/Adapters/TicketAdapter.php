<?php

namespace Plugin\MobileApp\Adapters;

use App\Exceptions\ApiException;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobilePaginator;

final class TicketAdapter
{
    public const FORBIDDEN_FIELDS = ['attachmentUpload', 'privateKey', 'user_id'];

    public function __construct(private readonly TicketService $tickets)
    {
    }

    public static function opaqueTicketId(int $ticketId): string
    {
        return substr(hash('sha256', 'mobile-ticket:' . $ticketId), 0, 32);
    }

    public function create(User $user, array $input): array
    {
        $subject = trim((string) ($input['subject'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $levelRaw = $input['level'] ?? null;
        if ($subject === '' || $message === '' || $levelRaw === null || $levelRaw === '' || !in_array((int) $levelRaw, [0, 1, 2], true)) {
            throw new MobileApiException('TICKET_EMPTY', 400);
        }
        try {
            $ticket = $this->tickets->createTicket($user->id, $subject, (int) $levelRaw, $message);
        } catch (ApiException $exception) {
            if (str_contains($exception->getMessage(), '未关闭')) {
                throw new MobileApiException('TICKET_OPEN_EXISTS', 409);
            }
            throw new MobileApiException('INTERNAL_ERROR', 500);
        }
        HookManager::call('ticket.create.after', $ticket);
        return ['ticketId' => self::opaqueTicketId((int) $ticket->id), 'status' => 'open'];
    }

    public function list(User $user, int $page, int $perPage): array
    {
        [$page, $perPage] = MobilePaginator::normalize($page, $perPage);
        $query = Ticket::query()->where('user_id', $user->id)->orderBy('created_at', 'DESC');
        $total = (int) $query->count();
        $rows = $query->forPage($page, $perPage)->get();
        $items = [];
        foreach ($rows as $ticket) {
            $items[] = [
                'ticketId' => self::opaqueTicketId((int) $ticket->id),
                'subject' => (string) $ticket->subject,
                'status' => $this->statusOf($ticket),
                'updatedAt' => $this->timestamp($this->epoch($ticket->updated_at ?: $ticket->created_at)),
            ];
        }
        return MobilePaginator::payload($items, $page, $perPage, $total);
    }

    public function detail(User $user, string $opaqueTicketId): array
    {
        $ticket = $this->owned($user, $opaqueTicketId);
        $messages = [];
        foreach (TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id', 'ASC')->get() as $message) {
            $messages[] = [
                'messageId' => (string) $message->id,
                'body' => (string) $message->message,
                'fromUser' => (int) $message->user_id === (int) $ticket->user_id,
                'createdAt' => $this->timestamp($this->epoch($message->created_at)),
            ];
        }
        return [
            'ticketId' => self::opaqueTicketId((int) $ticket->id),
            'subject' => (string) $ticket->subject,
            'status' => $this->statusOf($ticket),
            'waitingReply' => (int) $ticket->reply_status === Ticket::STATUS_OPENING,
            'messages' => $messages,
        ];
    }

    public function reply(User $user, string $opaqueTicketId, array $input): array
    {
        $ticket = $this->owned($user, $opaqueTicketId);
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            throw new MobileApiException('TICKET_EMPTY', 400);
        }
        if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
            throw new MobileApiException('TICKET_CLOSED', 403);
        }
        $last = TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id', 'DESC')->first();
        if ((int) admin_setting('ticket_must_wait_reply', 0) && $last && (int) $last->user_id === (int) $user->id) {
            throw new MobileApiException('TICKET_WAIT_REPLY', 403);
        }
        $created = $this->tickets->reply($ticket, $message, $user->id);
        if ($created === false || !$created) {
            throw new MobileApiException('INTERNAL_ERROR', 500);
        }
        HookManager::call('ticket.reply.user.after', $ticket);
        return ['messageId' => (string) $created->id];
    }

    public function close(User $user, string $opaqueTicketId): array
    {
        $ticket = $this->owned($user, $opaqueTicketId);
        if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
            throw new MobileApiException('TICKET_ALREADY_CLOSED', 409);
        }
        $ticket->status = Ticket::STATUS_CLOSED;
        $ticket->save();
        return ['status' => 'closed'];
    }

    private function owned(User $user, string $opaqueTicketId): Ticket
    {
        $tickets = Ticket::query()->where('user_id', $user->id)->get();
        foreach ($tickets as $ticket) {
            if (hash_equals(self::opaqueTicketId((int) $ticket->id), $opaqueTicketId)) {
                return $ticket;
            }
        }
        $foreignIds = Ticket::query()->where('user_id', '!=', $user->id)->pluck('id');
        foreach ($foreignIds as $id) {
            if (hash_equals(self::opaqueTicketId((int) $id), $opaqueTicketId)) {
                throw new MobileApiException('AUTH_FORBIDDEN', 403);
            }
        }
        throw new MobileApiException('TICKET_NOT_FOUND', 404);
    }

    private function statusOf(Ticket $ticket): string
    {
        return (int) $ticket->status === Ticket::STATUS_CLOSED ? 'closed' : 'open';
    }

    private function timestamp(int $epoch): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', max(0, $epoch));
    }

    private function epoch(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return max(0, $value->getTimestamp());
        }
        return max(0, (int) ($value ?: 0));
    }
}
