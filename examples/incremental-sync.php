<?php

/**
 * Incremental sync with state tracking (Raynet <-> Daktela).
 *
 * Only syncs records that changed since the last run. State is persisted
 * in a JSON file so subsequent runs pick up where the previous one left off.
 *
 * This example is self-contained (does not require bootstrap.php) because
 * it needs different engine wiring with a state store.
 *
 * Usage:
 *   php examples/incremental-sync.php                  # incremental sync
 *   php examples/incremental-sync.php --force-full     # ignore state, sync everything
 *   php examples/incremental-sync.php --reset-state    # clear state and exit
 *
 * @see docs/09-production-deployment.md in daktela/crm-sync for a production-ready version
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
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncEngine;
use Psr\Log\AbstractLogger;

// --- Logger ---
$logger = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        fprintf(STDERR, "[%s] %s: %s\n", $timestamp, strtoupper((string) $level), $message);
    }
};

// --- Parse CLI flags ---
$forceFull = in_array('--force-full', $argv, true);
$resetState = in_array('--reset-state', $argv, true);

// --- Load configuration ---
$configPath = getenv('SYNC_CONFIG_PATH') ?: __DIR__ . '/../config/sync.yaml';
$raynetConfig = (new RaynetConfigLoader())->load($configPath);
$syncConfig = (new YamlConfigLoader())->load($configPath);

// --- State store ---
$stateFilePath = __DIR__ . '/../var/sync-state.json';
$stateStore = new FileSyncStateStore($stateFilePath);

if ($resetState) {
    $stateStore->clearAll();
    $logger->info('Sync state has been reset');
    exit(0);
}

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

// --- Register Raynet-specific transformers ---
$transformerRegistry = TransformerRegistry::withDefaults();
$transformerRegistry->register(new RaynetDateTransformer());
$transformerRegistry->register(new NameJoinTransformer());

// --- Create engine with state store for incremental sync ---
$engine = new SyncEngine($ccAdapter, $crmAdapter, $syncConfig, $logger, $transformerRegistry, $stateStore);

// --- Run sync ---
if ($forceFull) {
    $logger->info('Starting forced full sync (ignoring state)...');
} else {
    $logger->info('Starting incremental sync...');
}

$results = $engine->fullSync(forceFullSync: $forceFull);

foreach ($results as $entityType => $result) {
    $logger->info(sprintf(
        '%s: %d total, %d created, %d updated, %d skipped, %d failed (%.2fs)',
        ucfirst($entityType),
        $result->getTotalCount(),
        $result->getCreatedCount(),
        $result->getUpdatedCount(),
        $result->getSkippedCount(),
        $result->getFailedCount(),
        $result->getDuration(),
    ));
}

$logger->info('Sync complete');
