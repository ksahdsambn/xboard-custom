<?php

namespace Plugin\MobileApp\Exceptions;

use Plugin\MobileApp\Support\MobileErrorCatalog;
use RuntimeException;

final class MobileApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $http = null,
        public readonly ?string $overrideMessage = null
    ) {
        parent::__construct($errorCode);
    }

    public function httpStatus(): int
    {
        return MobileErrorCatalog::resolveHttp($this->errorCode, $this->http);
    }

    public function displayMessage(string $locale): string
    {
        return $this->overrideMessage ?? MobileErrorCatalog::message($this->errorCode, $locale);
    }
}
