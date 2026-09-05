<?php

namespace Plugin\MobileApp\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Adapters\TicketAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\PluginStatus;

class TicketController extends PluginController
{
    public function __construct(private readonly TicketAdapter $tickets)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->tickets->create($this->user(), $this->payload($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $this->guardEnabled();
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('perPage', 20);
        return MobileEnvelope::success($this->tickets->list($this->user(), $page, $perPage));
    }

    public function show(Request $request, string $ticketId): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->tickets->detail($this->user(), $ticketId));
    }

    public function reply(Request $request, string $ticketId): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->tickets->reply($this->user(), $ticketId, $this->payload($request)));
    }

    public function close(Request $request, string $ticketId): JsonResponse
    {
        $this->guardEnabled();
        return MobileEnvelope::success($this->tickets->close($this->user(), $ticketId));
    }

    private function user(): User
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user instanceof User) {
            throw new MobileApiException('AUTH_SESSION_INVALID', 403);
        }
        return $user;
    }

    private function payload(Request $request): array
    {
        $json = $request->json()?->all() ?: [];
        return array_merge($request->query(), $request->request->all(), is_array($json) ? $json : []);
    }

    private function guardEnabled(): void
    {
        if (!PluginStatus::isEnabled()) {
            throw new MobileApiException('SERVICE_MAINTENANCE', 503);
        }
    }
}
