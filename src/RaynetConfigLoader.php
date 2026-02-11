<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet;

use Daktela\CrmSync\Config\YamlConfigLoader;

/**
 * Loads RaynetConfiguration from the `raynet:` section of a sync YAML config file.
 *
 * This reads the same sync.yaml that the SDK's YamlConfigLoader uses,
 * extracting the adapter-specific `raynet:` section. The SDK handles
 * YAML parsing and ${ENV_VAR} placeholder resolution.
 */
final class RaynetConfigLoader
{
    public function __construct(
        private readonly YamlConfigLoader $yamlLoader = new YamlConfigLoader(),
    ) {
    }

    public function load(string $configPath): RaynetConfiguration
    {
        $data = $this->yamlLoader->loadRaw($configPath);

        if (!isset($data['raynet']) || !is_array($data['raynet'])) {
            throw new \RuntimeException(
                sprintf('Missing "raynet:" section in config file: %s', $configPath),
            );
        }

        $config = $data['raynet'];

        return new RaynetConfiguration(
            apiUrl: (string) ($config['api_url'] ?? 'https://app.raynet.cz/api/v2/'),
            email: (string) ($config['email'] ?? ''),
            apiKey: (string) ($config['api_key'] ?? ''),
            instanceName: (string) ($config['instance_name'] ?? ''),
            personType: (string) ($config['person_type'] ?? 'person'),
            ownerId: (int) ($config['owner_id'] ?? 0),
        );
    }
}
