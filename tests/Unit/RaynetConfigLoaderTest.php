<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Tests\Unit;

use Daktela\CrmSync\Raynet\RaynetConfigLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RaynetConfigLoaderTest extends TestCase
{
    #[Test]
    public function itLoadsConfigFromYaml(): void
    {
        $yaml = <<<'YAML'
daktela:
  instance_url: "https://daktela.example.com"
  access_token: "daktela-token"

raynet:
  api_url: "https://app.raynet.cz/api/v2/"
  email: "user@example.com"
  api_key: "secret-key"
  instance_name: "test-instance"
  person_type: "contact-person"
YAML;

        $tmpFile = tempnam(sys_get_temp_dir(), 'sync_') . '.yaml';
        file_put_contents($tmpFile, $yaml);

        try {
            $loader = new RaynetConfigLoader();
            $config = $loader->load($tmpFile);

            self::assertSame('https://app.raynet.cz/api/v2/', $config->apiUrl);
            self::assertSame('user@example.com', $config->email);
            self::assertSame('secret-key', $config->apiKey);
            self::assertSame('test-instance', $config->instanceName);
            self::assertSame('contact-person', $config->personType);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function itUsesDefaultsForOptionalFields(): void
    {
        $yaml = <<<'YAML'
raynet:
  email: "user@example.com"
  api_key: "key"
  instance_name: "instance"
YAML;

        $tmpFile = tempnam(sys_get_temp_dir(), 'sync_') . '.yaml';
        file_put_contents($tmpFile, $yaml);

        try {
            $loader = new RaynetConfigLoader();
            $config = $loader->load($tmpFile);

            self::assertSame('https://app.raynet.cz/api/v2/', $config->apiUrl);
            self::assertSame('person', $config->personType);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function itResolvesEnvVarPlaceholders(): void
    {
        putenv('TEST_RAYNET_EMAIL=env@example.com');
        putenv('TEST_RAYNET_KEY=env-key');

        $yaml = <<<'YAML'
raynet:
  email: "${TEST_RAYNET_EMAIL}"
  api_key: "${TEST_RAYNET_KEY}"
  instance_name: "literal-instance"
YAML;

        $tmpFile = tempnam(sys_get_temp_dir(), 'sync_') . '.yaml';
        file_put_contents($tmpFile, $yaml);

        try {
            $loader = new RaynetConfigLoader();
            $config = $loader->load($tmpFile);

            self::assertSame('env@example.com', $config->email);
            self::assertSame('env-key', $config->apiKey);
            self::assertSame('literal-instance', $config->instanceName);
        } finally {
            unlink($tmpFile);
            putenv('TEST_RAYNET_EMAIL');
            putenv('TEST_RAYNET_KEY');
        }
    }

    #[Test]
    public function itThrowsWhenRaynetSectionMissing(): void
    {
        $yaml = <<<'YAML'
daktela:
  instance_url: "https://example.com"
YAML;

        $tmpFile = tempnam(sys_get_temp_dir(), 'sync_') . '.yaml';
        file_put_contents($tmpFile, $yaml);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Missing "raynet:" section');

            $loader = new RaynetConfigLoader();
            $loader->load($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }
}
