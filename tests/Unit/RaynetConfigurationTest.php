<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Tests\Unit;

use Daktela\CrmSync\Raynet\RaynetConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RaynetConfigurationTest extends TestCase
{
    #[Test]
    public function itStoresAllProperties(): void
    {
        $config = new RaynetConfiguration(
            apiUrl: 'https://app.raynet.cz/api/v2/',
            email: 'test@example.com',
            apiKey: 'secret-key',
            instanceName: 'my-instance',
            personType: 'contact-person',
        );

        self::assertSame('https://app.raynet.cz/api/v2/', $config->apiUrl);
        self::assertSame('test@example.com', $config->email);
        self::assertSame('secret-key', $config->apiKey);
        self::assertSame('my-instance', $config->instanceName);
        self::assertSame('contact-person', $config->personType);
    }

    #[Test]
    public function itDefaultsToPersonType(): void
    {
        $config = new RaynetConfiguration(
            apiUrl: 'https://app.raynet.cz/api/v2/',
            email: 'test@example.com',
            apiKey: 'key',
            instanceName: 'instance',
        );

        self::assertSame('person', $config->personType);
    }

    #[Test]
    public function itReturnsPersonEndpointForPersonType(): void
    {
        $config = new RaynetConfiguration(
            apiUrl: 'https://app.raynet.cz/api/v2/',
            email: 'test@example.com',
            apiKey: 'key',
            instanceName: 'instance',
            personType: 'person',
        );

        self::assertSame('person', $config->getPersonEndpoint());
    }

    #[Test]
    public function itReturnsContactPersonEndpointForContactPersonType(): void
    {
        $config = new RaynetConfiguration(
            apiUrl: 'https://app.raynet.cz/api/v2/',
            email: 'test@example.com',
            apiKey: 'key',
            instanceName: 'instance',
            personType: 'contact-person',
        );

        self::assertSame('contact-person', $config->getPersonEndpoint());
    }
}
