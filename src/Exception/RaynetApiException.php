<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Exception;

use Daktela\CrmSync\Exception\AdapterException;

class RaynetApiException extends AdapterException
{
    public static function fromResponse(int $statusCode, string $body): self
    {
        return new self(
            sprintf('Raynet API error (HTTP %d): %s', $statusCode, $body),
        );
    }

    public static function connectionFailed(string $message): self
    {
        return new self(
            sprintf('Raynet API connection failed: %s', $message),
        );
    }
}
