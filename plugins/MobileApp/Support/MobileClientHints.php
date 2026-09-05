<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\Request;

final class MobileClientHints
{
    public function __construct(
        public readonly string $appVersion,
        public readonly ?int $androidApi,
        public readonly string $libxrayVersion,
        public readonly string $xrayCoreVersion,
        public readonly string $region
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $android = $request->headers->get('X-Android-Api');
        return new self(
            appVersion: (string) ($request->headers->get('X-App-Version') ?: '1.0.0'),
            androidApi: is_numeric($android) ? (int) $android : null,
            libxrayVersion: (string) ($request->headers->get('X-Libxray-Version') ?: ''),
            xrayCoreVersion: (string) ($request->headers->get('X-Xray-Core-Version') ?: ''),
            region: strtoupper((string) ($request->headers->get('X-Mobile-Region') ?: '')),
        );
    }
}
