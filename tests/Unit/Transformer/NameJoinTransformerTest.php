<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Tests\Unit\Transformer;

use Daktela\CrmSync\Raynet\Transformer\NameJoinTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NameJoinTransformerTest extends TestCase
{
    private NameJoinTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new NameJoinTransformer();
    }

    #[Test]
    public function itHasCorrectName(): void
    {
        self::assertSame('name_join', $this->transformer->getName());
    }

    #[Test]
    public function itJoinsFirstAndLastName(): void
    {
        $result = $this->transformer->transform(['John', 'Doe']);

        self::assertSame('John Doe', $result);
    }

    #[Test]
    public function itHandlesPartialFirstNameOnly(): void
    {
        $result = $this->transformer->transform(['John', '']);

        self::assertSame('John', $result);
    }

    #[Test]
    public function itHandlesPartialLastNameOnly(): void
    {
        $result = $this->transformer->transform(['', 'Doe']);

        self::assertSame('Doe', $result);
    }

    #[Test]
    public function itHandlesNullValuesInArray(): void
    {
        $result = $this->transformer->transform([null, 'Doe']);

        self::assertSame('Doe', $result);
    }

    #[Test]
    public function itPassesThroughStringValue(): void
    {
        self::assertSame('John Doe', $this->transformer->transform('John Doe'));
    }

    #[Test]
    public function itHandlesNullInput(): void
    {
        self::assertSame('', $this->transformer->transform(null));
    }

    #[Test]
    public function itUsesCustomSeparator(): void
    {
        $result = $this->transformer->transform(['John', 'Doe'], ['separator' => ', ']);

        self::assertSame('John, Doe', $result);
    }
}
