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
    ];
}
