<?php

namespace Plugin\MobileApp\Services;

use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Plugin\MobileApp\Adapters\PlanAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Models\PlayProduct;
use Plugin\MobileApp\Models\PlayProductAudit;
use Plugin\MobileApp\Support\MobileLogRedactor;
use Plugin\MobileApp\Support\MobileRequestId;

final class PlayProductService
{
    public const PACKAGE_NAME = 'dev.xboard.xboard_mobile';

    public const ENVIRONMENTS = ['sandbox', 'production'];

    public function __construct(private readonly PlanService $plans)
    {
    }

    public function listAdmin(): array
    {
        return PlayProduct::query()
            ->orderBy('id')
            ->get()
            ->map(fn (PlayProduct $row): array => $this->adminDto($row))
            ->all();
    }

    public function listSellable(array $clientClaims = []): array
    {
        $clientClaims = EntitlementService::stripClientClaims($clientClaims);
        unset($clientClaims);
        $environment = PlanAdapter::playEnvironment();
        $available = $this->plans->getAvailablePlans()->keyBy(fn (Plan $plan): int => (int) $plan->id);
        $mapped = PlayProduct::query()
            ->where('enabled', true)
            ->where('environment', $environment)
            ->where('package_name', self::PACKAGE_NAME)
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($mapped as $product) {
            $plan = $available->get((int) $product->xboard_plan_id);
            if (!$plan instanceof Plan) {
                continue;
            }
            $item = [
                'opaquePlanId' => EntitlementService::opaquePlanId((int) $plan->id),
                'name' => (string) $plan->name,
                'playProductId' => (string) $product->product_id,
                'playBasePlanId' => (string) $product->base_plan_id,
                'environment' => (string) $product->environment,
            ];
            foreach (PlanAdapter::FORBIDDEN_FIELDS as $field) {
                unset($item[$field]);
            }
            $items[] = $item;
        }
        MobileLogRedactor::error('plans_list', ['count' => count($items), 'environment' => $environment]);
        return $items;
    }

    public function upsert(array $input, ?int $actorId = null): PlayProduct
    {
        $normalized = $this->normalize($input);
        return DB::transaction(function () use ($normalized, $actorId): PlayProduct {
            $existing = PlayProduct::query()
                ->where('package_name', $normalized['package_name'])
                ->where('product_id', $normalized['product_id'])
                ->where('environment', $normalized['environment'])
                ->lockForUpdate()
                ->first();
            if ($existing instanceof PlayProduct) {
                return $this->updateExisting($existing, $normalized, $actorId);
            }
            return $this->insertNew($normalized, $actorId);
        });
    }

    public function create(array $input, ?int $actorId = null): PlayProduct
    {
        $normalized = $this->normalize($input);
        return DB::transaction(function () use ($normalized, $actorId): PlayProduct {
            $existing = PlayProduct::query()
                ->where('package_name', $normalized['package_name'])
                ->where('product_id', $normalized['product_id'])
                ->where('environment', $normalized['environment'])
                ->lockForUpdate()
                ->first();
            if ($existing instanceof PlayProduct) {
                throw new MobileApiException('PLAY_PRODUCT_DUPLICATE', 409);
            }
            return $this->insertNew($normalized, $actorId);
        });
    }

    private function insertNew(array $normalized, ?int $actorId): PlayProduct
    {
        try {
            $row = PlayProduct::query()->create($normalized);
        } catch (UniqueConstraintViolationException $exception) {
            throw new MobileApiException('PLAY_PRODUCT_DUPLICATE', 409);
        } catch (\Illuminate\Database\QueryException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'UNIQUE') || str_contains($message, 'unique') || (string) $exception->getCode() === '23000') {
                throw new MobileApiException('PLAY_PRODUCT_DUPLICATE', 409);
            }
            throw $exception;
        }
        $this->audit('create', null, $row, $actorId, $normalized['environment'], $normalized['request_id']);
        return $row;
    }

    private function updateExisting(PlayProduct $existing, array $normalized, ?int $actorId): PlayProduct
    {
        $before = $existing->toArray();
        $existing->fill([
            'base_plan_id' => $normalized['base_plan_id'],
            'xboard_plan_id' => $normalized['xboard_plan_id'],
            'enabled' => $normalized['enabled'],
            'request_id' => $normalized['request_id'],
        ]);
        $existing->save();
        $action = $normalized['enabled'] ? ($before['enabled'] ? 'update' : 'enable') : 'disable';
        $this->audit($action, $before, $existing, $actorId, $normalized['environment'], $normalized['request_id']);
        return $existing;
    }

    private function normalize(array $input): array
    {
        $input = EntitlementService::stripClientClaims($input);
        unset($input['price'], $input['duration'], $input['expiresAt'], $input['stripeUrl'], $input['bepusdt'], $input['walletTopup'], $input['webCheckout']);

        $package = trim((string) ($input['packageName'] ?? $input['package_name'] ?? ''));
        $productId = trim((string) ($input['productId'] ?? $input['product_id'] ?? $input['playProductId'] ?? ''));
        $basePlanId = trim((string) ($input['basePlanId'] ?? $input['base_plan_id'] ?? $input['playBasePlanId'] ?? ''));
        $environment = strtolower(trim((string) ($input['environment'] ?? '')));
        $planId = $input['xboardPlanId'] ?? $input['xboard_plan_id'] ?? null;
        $enabled = array_key_exists('enabled', $input) ? (bool) $input['enabled'] : true;
        $requestId = trim((string) ($input['request_id'] ?? ''));
        if ($requestId === '') {
            try {
                $requestId = MobileRequestId::resolve();
            } catch (\Throwable) {
                $requestId = (string) \Illuminate\Support\Str::uuid();
            }
        }

        if ($package !== self::PACKAGE_NAME) {
            throw new MobileApiException('PLAY_PRODUCT_INVALID', 400);
        }
        if ($productId === '' || $basePlanId === '') {
            throw new MobileApiException('PLAY_PRODUCT_INVALID', 400);
        }
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new MobileApiException('PLAY_PRODUCT_INVALID', 400);
        }
        $resolvedPlanId = $this->resolvePlanId($planId, (string) ($input['opaquePlanId'] ?? ''), $enabled);

        return [
            'package_name' => $package,
            'product_id' => $productId,
            'base_plan_id' => $basePlanId,
            'environment' => $environment,
            'xboard_plan_id' => $resolvedPlanId,
            'enabled' => $enabled,
            'request_id' => $requestId,
        ];
    }

    private function resolvePlanId(mixed $planId, string $opaquePlanId, bool $mustBeSellable): int
    {
        $candidates = $mustBeSellable
            ? $this->plans->getAvailablePlans()
            : Plan::query()->orderBy('id')->get();
        if (is_numeric($planId)) {
            $match = $candidates->first(fn (Plan $plan): bool => (int) $plan->id === (int) $planId);
            if ($match instanceof Plan) {
                return (int) $match->id;
            }
            throw new MobileApiException('PLAY_PRODUCT_INVALID', 400);
        }
        if ($opaquePlanId !== '') {
            foreach ($candidates as $plan) {
                if (hash_equals(EntitlementService::opaquePlanId((int) $plan->id), $opaquePlanId)) {
                    return (int) $plan->id;
                }
            }
        }
        throw new MobileApiException('PLAY_PRODUCT_INVALID', 400);
    }

    private function adminDto(PlayProduct $row): array
    {
        return [
            'id' => (int) $row->id,
            'packageName' => (string) $row->package_name,
            'productId' => (string) $row->product_id,
            'playBasePlanId' => (string) $row->base_plan_id,
            'environment' => (string) $row->environment,
            'opaquePlanId' => EntitlementService::opaquePlanId((int) $row->xboard_plan_id),
            'enabled' => (bool) $row->enabled,
        ];
    }

    private function audit(string $action, ?array $before, PlayProduct $after, ?int $actorId, string $environment, string $requestId): void
    {
        PlayProductAudit::query()->create([
            'action' => $action,
            'play_product_id' => $after->id,
            'before_json' => $this->redactAudit($before),
            'after_json' => $this->redactAudit($after->toArray()),
            'actor_user_id' => $actorId,
            'request_id' => $requestId,
            'environment' => $environment,
        ]);
        MobileLogRedactor::error('play_product_audit', [
            'action' => $action,
            'productId' => $after->product_id,
            'environment' => $environment,
        ]);
    }

    private function redactAudit(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        unset($payload['price'], $payload['duration'], $payload['expiresAt'], $payload['privateKey']);
        return $payload;
    }
}
