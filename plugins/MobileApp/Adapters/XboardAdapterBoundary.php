<?php

namespace Plugin\MobileApp\Adapters;

final class XboardAdapterBoundary
{
    public const FORBIDDEN = [
        'modify official routes',
        'modify official migrations',
        'use subscription token as mobile session',
        'use AuthService::findUserByBearerToken for mobile session',
        'query nodes by primary key bypassing ServerService',
        'accept client plan, group, traffic or expiry claims',
        'return web checkout, stripe, bepusdt or wallet topup on Play plans',
        'emit mobile entitlement without EntitlementService',
    ];
}
