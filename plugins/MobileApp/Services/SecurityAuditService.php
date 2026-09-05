<?php

namespace Plugin\MobileApp\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\MobileApp\Adapters\AuthAdapter;
use Plugin\MobileApp\Models\SecurityAudit;
use Plugin\MobileApp\Support\MobileLogRedactor;
use Plugin\MobileApp\Support\MobilePaginator;
use Plugin\MobileApp\Support\MobileRequestId;

final class SecurityAuditService
{
    public const OPERATIONS = ['auth', 'profile', 'purchase', 'rtdn', 'deletion', 'diagnostic'];

    /**
     * @param array<string, mixed> $meta
     */
    public static function record(
        Request $request,
        string $operation,
        string $outcome,
        ?string $errorCode,
        int $latencyMs,
        array $meta = []
    ): void {
        $user = Auth::guard('sanctum')->user();
        $opaque = $user instanceof User ? AuthAdapter::opaqueAccountId($user) : null;
        $safeMeta = MobileLogRedactor::redact($meta);
        if (!is_array($safeMeta)) {
            $safeMeta = [];
        }
        try {
            SecurityAudit::query()->create([
                'request_id' => MobileRequestId::resolve($request),
                'operation' => $operation,
                'outcome' => $outcome,
                'error_code' => $errorCode,
                'latency_ms' => $latencyMs,
                'actor_opaque_id' => $opaque,
                'route' => (string) (optional($request->route())->getName() ?: $request->path()),
                'environment' => function_exists('app') ? (string) app()->environment() : 'testing',
                'meta_json' => $safeMeta,
            ]);
        } catch (\Throwable) {
            MobileLogRedactor::error('security_audit_write_failed', [
                'operation' => $operation,
                'outcome' => $outcome,
            ], $request);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function list(int $page, int $perPage): array
    {
        [$page, $perPage] = MobilePaginator::normalize($page, $perPage);
        $query = SecurityAudit::query()->orderByDesc('id');
        $total = (int) $query->count();
        $rows = $query->forPage($page, $perPage)->get()->map(function (SecurityAudit $row): array {
            return [
                'requestId' => $row->request_id,
                'operation' => $row->operation,
                'outcome' => $row->outcome,
                'errorCode' => $row->error_code,
                'latencyMs' => (int) $row->latency_ms,
                'actorOpaqueId' => $row->actor_opaque_id,
                'route' => $row->route,
                'createdAt' => optional($row->created_at)?->toIso8601String(),
            ];
        })->all();
        return MobilePaginator::payload($rows, $page, $perPage, $total);
    }
}
