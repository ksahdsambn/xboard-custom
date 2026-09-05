<?php

namespace Plugin\MobileApp\Adapters;

use App\Models\Plan;
use App\Services\PlanService;
use Plugin\MobileApp\Services\EntitlementService;
use Plugin\MobileApp\Services\PlayProductService;

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
        return (new PlayProductService(new PlanService(new Plan())))->listSellable($clientClaims);
    }

    public static function playEnvironment(): string
    {
        if (function_exists('app') && app()->environment('production')) {
            return 'production';
        }
        return 'sandbox';
    }
}
