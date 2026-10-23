<?php

namespace MyPinacle\Meta\Catalog;

final class CatalogApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $metaCode = 0, public readonly int $httpStatus = 0, public readonly ?int $subcode = null)
    {
        parent::__construct($message, $metaCode);
    }

    /** 80014: too many Catalog Batch calls for this catalog in the rolling minute. Also generic throttling codes. */
    public function isRateLimit(): bool
    {
        return in_array($this->metaCode, [80014, 4, 17, 32, 613], true) || $this->httpStatus === 429;
    }

    /** 190: invalid / expired / revoked access token. 200 / 10: permission missing. */
    public function needsReconnect(): bool
    {
        return in_array($this->metaCode, [190, 200, 10], true);
    }

    public function isTransient(): bool
    {
        return $this->isRateLimit() || $this->httpStatus >= 500 || $this->httpStatus === 0 || in_array($this->metaCode, [1, 2], true);
    }
}