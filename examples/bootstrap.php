<?php

/**
 * Shared bootstrap for Raynet example scripts.
 *
 * Creates both adapters (Daktela + Raynet), verifies connections, registers
 * Raynet-specific transformers, and wires the SyncEngine.
 *
 * Everything is configured through a single sync.yaml file. No .env file needed.
 * Values can be literal or use ${ENV_VAR} placeholders for production deployments.
 *
 * Copy this file to your project (e.g. bin/sync.php) and adjust the config path.
 *
 * Expected project layout:
 *
 *   your-project/
 *   ├── composer.json          # requires daktela/crm-sync-raynet
 *   ├── config/
 *   │   ├── sync.yaml          # single config file (copied from vendor config-dist)
 *   │   └── mappings/
 *   │       ├── contacts.yaml
 *   │       ├── accounts.yaml
 *   │       └── activities.yaml
 *   └── bin/
 *       └── sync.php           # this file
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Raynet\RaynetClient;
use Daktela\CrmSync\Raynet\RaynetConfigLoader;
use Daktela\CrmSync\Raynet\RaynetCrmAdapter;
use Daktela\CrmSync\Raynet\Transformer\NameJoinTransformer;
use Daktela\CrmSync\Raynet\Transformer\RaynetDateTransformer;
use Daktela\CrmSync\Sync\SyncEngine;
use Psr\Log\AbstractLogger;

// --- Logger (replace with Monolog or your PSR-3 logger in production) ---
$logger = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        fprintf(STDERR, "[%s] %s: %s\n", $timestamp, strtoupper((string) $level), $message);
    }
};

// --- Single config file for everything ---
$configPath = getenv('SYNC_CONFIG_PATH') ?: __DIR__ . '/../config/sync.yaml';

// Load Raynet adapter config from the "raynet:" section
$raynetConfig = (new RaynetConfigLoader())->load($configPath);

// Load SDK sync config from the "daktela:", "sync:", "webhook:" sections
$syncConfig = (new YamlConfigLoader())->load($configPath);

// --- Create Raynet CRM adapter ---
$raynetClient = new RaynetClient($raynetConfig, null, $logger);
$crmAdapter = new RaynetCrmAdapter($raynetClient, $raynetConfig, $logger);

if (!$crmAdapter->ping()) {
    throw new RuntimeException('Cannot connect to Raynet CRM API');
}
$logger->info('Raynet CRM connection OK');

// --- Create Daktela Contact Centre adapter ---
$ccAdapter = new DaktelaAdapter(
    $syncConfig->instanceUrl,
    $syncConfig->accessToken,
    $logger,
);

if (!$ccAdapter->ping()) {
    throw new RuntimeException('Cannot connect to Daktela API');
}
$logger->info('Daktela connection OK');

// --- Register Raynet-specific transformers alongside SDK defaults ---
$transformerRegistry = TransformerRegistry::withDefaults();
$transformerRegistry->register(new RaynetDateTransformer());
$transformerRegistry->register(new NameJoinTransformer());

// --- Create the sync engine ---
$engine = new SyncEngine($ccAdapter, $crmAdapter, $syncConfig, $logger, $transformerRegistry);
