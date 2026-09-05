<?php

namespace Plugin\MobileApp\Adapters;

final class XboardAdapterBoundary
{
    public const FORBIDDEN = [
        'modify official routes',
        'modify official migrations',
        'use subscription token as mobile session',
        'query nodes by primary key bypassing ServerService',
    ];
}
