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
        'cache authorized node sets across requests',
        'return node list credentials, public keys, short IDs or protocol settings',
        'accept client plan, group, traffic or expiry claims',
        'return web checkout, stripe, bepusdt or wallet topup on Play plans',
        'emit mobile entitlement without EntitlementService',
        'emit mobile node list without EntitlementService and ServerService',
        'emit mobile profile without EntitlementService and ServerService',
        'return Reality private keys or raw protocol settings in Profile DTO',
        'passthrough official Notice model fields or share notice read state across users',
        'expose other users tickets or fake attachment upload capability',
        'collect advertising or hardware identifiers or grant entitlement from device registration',
        'treat logout or disable as account deletion',
        'cancel Play subscriptions when deleting an Xboard account',
        'keep sessions, email or Profile access after account deletion execute',
        'trust client price, plan id, duration or expiry for Play product mapping',
        'map Play products with a package name other than the Android applicationId',
        'share sandbox and production Play product mappings',
        'expose stripe, bepusdt, wallet topup or web checkout in the Play catalog',
        'grant entitlement from Play product mapping without purchase verification',
        'trust client price, plan, duration or entitlement for Play purchases',
        'acknowledge a Play purchase before persisting the token and recording a ledger grant',
        'acknowledge Play renewals',
        'store raw purchase tokens or call production Google Play from development fixtures',
        'project Play ledger onto Xboard user plan fields before TASK-033',
    ];
}
