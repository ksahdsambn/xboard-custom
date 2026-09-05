<?php

namespace Plugin\MobileApp\Services;

final class MobileAppManifestService
{
    public function prefixes(): array
    {
        return [
            0 => '/api/mobile/v0',
            1 => '/api/mobile/v1',
        ];
    }

    public function currentVersion(): string
    {
        return '1.0.0';
    }
}
