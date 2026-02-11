<?php

/**
 * Full sync of all entity types (Raynet <-> Daktela).
 *
 * Runs accounts, contacts, and activities in the correct dependency order.
 * This is the recommended approach for scheduled (cron) syncs.
 *
 * Usage: php examples/full-sync.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$logger->info('Starting full sync...');

$results = $engine->fullSync();

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

$logger->info('Full sync complete');
