<?php

namespace Plugin\MobileApp\Adapters;

use App\Models\Plan;
use App\Services\PlanService;
use Plugin\MobileApp\Models\PlayProduct;
use Plugin\MobileApp\Services\EntitlementService;
use Plugin\MobileApp\Support\MobileLogRedactor;

final class PlanAdapter
{
    public const PACKAGE_NAME = 'dev.xboard.xboard_mobile';

    public const FORBIDDEN_FIELDS = [
        'stripeUrl',
        'bepusdt',
        'walletTopup',
        'webCheckout',
        'privateKey',
        'groupId',
        'group_id',
        'planId',
        'id',
        'prices',
    ];

    public function listSellablePlayPlans(array $clientClaims = []): array
    {
        $clientClaims = EntitlementService::stripClientClaims($clientClaims);
        unset($clientClaims);
        $environment = self::playEnvironment();
        $plans = (new PlanService(new Plan()))->getAvailablePlans();
        $mapped = PlayProduct::query()
            ->where('enabled', true)
            ->where('environment', $environment)
            ->where('package_name', self::PACKAGE_NAME)
            ->get()
            ->keyBy(fn (PlayProduct $row): int => (int) $row->xboard_plan_id);

        $items = [];
        foreach ($plans as $plan) {
            $product = $mapped->get((int) $plan->id);
            if (!$product instanceof PlayProduct) {
                continue;
            }
            $item = [
                'opaquePlanId' => EntitlementService::opaquePlanId((int) $plan->id),
                'name' => (string) $plan->name,
                'playProductId' => (string) $product->product_id,
                'playBasePlanId' => (string) $product->base_plan_id,
                'environment' => (string) $product->environment,
            ];
            foreach (self::FORBIDDEN_FIELDS as $field) {
                unset($item[$field]);
            }
            $items[] = $item;
        }
        MobileLogRedactor::error('plans_list', ['count' => count($items), 'environment' => $environment]);
        return $items;
    }

    public static function playEnvironment(): string
    {
        if (function_exists('app') && app()->environment('production')) {
            return 'production';
        }
        return 'sandbox';
    }
}
