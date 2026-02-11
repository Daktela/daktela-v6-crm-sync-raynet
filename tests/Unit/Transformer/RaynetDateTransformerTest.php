<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Tests\Unit\Transformer;

use Daktela\CrmSync\Raynet\Transformer\RaynetDateTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RaynetDateTransformerTest extends TestCase
{
    private RaynetDateTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new RaynetDateTransformer();
    }

    #[Test]
    public function itHasCorrectName(): void
    {
        self::assertSame('raynet_date', $this->transformer->getName());
    }

    #[Test]
    public function itConvertsDateTimeToRaynetFormat(): void
    {
        $result = $this->transformer->transform('2024-01-15 14:30:00', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d H:i',
        ]);

        self::assertSame('2024-01-15 14:30', $result);
    }

    #[Test]
    public function itConvertsRaynetFormatToDateTime(): void
    {
        $result = $this->transformer->transform('2024-01-15 14:30', [
            'from' => 'Y-m-d H:i',
            'to' => 'Y-m-d H:i:s',
        ]);

        self::assertSame('2024-01-15 14:30:00', $result);
    }

    #[Test]
    public function itReturnsNullForNullValue(): void
    {
        self::assertNull($this->transformer->transform(null));
    }

    #[Test]
    public function itReturnsEmptyStringForEmptyValue(): void
    {
        self::assertSame('', $this->transformer->transform(''));
    }

    #[Test]
    public function itReturnsOriginalValueOnInvalidDate(): void
    {
        self::assertSame('not-a-date', $this->transformer->transform('not-a-date', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d H:i',
        ]));
    }

    #[Test]
    public function itUsesDefaultFormats(): void
    {
        $result = $this->transformer->transform('2024-06-01 10:00:00');

        self::assertSame('2024-06-01 10:00', $result);
    }
}
