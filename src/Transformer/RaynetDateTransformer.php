<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Transformer;

use Daktela\CrmSync\Mapping\Transformer\ValueTransformerInterface;

final class RaynetDateTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'raynet_date';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $from = (string) ($params['from'] ?? 'Y-m-d H:i:s');
        $to = (string) ($params['to'] ?? 'Y-m-d H:i');

        $date = \DateTimeImmutable::createFromFormat($from, (string) $value);
        if ($date === false) {
            try {
                $date = new \DateTimeImmutable((string) $value);
            } catch (\Exception) {
                return $value;
            }
        }

        return $date->format($to);
    }
}
