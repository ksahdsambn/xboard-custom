<?php

namespace Plugin\MobileApp\Support;

final class MobileClientDecision
{
    public static function decide(int $http, array $body): string
    {
        $code = $body['errorCode'] ?? null;
        if ($code === 'AUTH_SESSION_INVALID' && in_array($http, [401, 403], true)) {
            return 're-login';
        }
        if (($body['status'] ?? null) === 'success') {
            return 'ok';
        }
        if (!is_string($code) || $code === '') {
            return 'undecidable';
        }
        if ($http === 403) {
            return 'business-reject';
        }
        return 'error:' . $code;
    }
}
