<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Transformer;

use Daktela\CrmSync\Mapping\Transformer\ValueTransformerInterface;

final class NameJoinTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'name_join';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if (is_array($value)) {
            $separator = (string) ($params['separator'] ?? ' ');
            $parts = array_filter($value, static fn (mixed $v): bool => $v !== null && $v !== '');

            return trim(implode($separator, array_map(strval(...), $parts)));
        }

        if (is_string($value)) {
            return $value;
        }

        return (string) ($value ?? '');
    }
}
