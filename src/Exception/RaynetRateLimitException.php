<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Exception;

class RaynetRateLimitException extends RaynetApiException
{
    public static function dailyLimitReached(): self
    {
        return new self('Raynet API daily rate limit (24,000 requests) reached');
    }
}
