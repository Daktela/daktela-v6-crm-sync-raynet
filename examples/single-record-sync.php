<?php

/**
 * Sync a single record by entity type and ID (Raynet <-> Daktela).
 *
 * Useful for debugging, manual re-syncs, or on-demand sync triggered
 * by an external system.
 *
 * Usage:
 *   php examples/single-record-sync.php contact 123
 *   php examples/single-record-sync.php account 456
 *   php examples/single-record-sync.php activity 789 call
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Daktela\CrmSync\Entity\ActivityType;

$entityType = $argv[1] ?? null;
$recordId = $argv[2] ?? null;

if ($entityType === null || $recordId === null) {
    fprintf(STDERR, "Usage: php %s <entity-type> <record-id> [activity-type]\n", $argv[0]);
    fprintf(STDERR, "  entity-type: contact, account, activity\n");
    fprintf(STDERR, "  activity-type: call, email, web, sms (required for activity)\n");
    exit(1);
}

$result = match ($entityType) {
    'contact' => $engine->syncContact($recordId),
    'account' => $engine->syncAccount($recordId),
    'activity' => (function () use ($engine, $recordId, $argv) {
        $activityTypeValue = $argv[3] ?? null;
        if ($activityTypeValue === null) {
            fprintf(STDERR, "Error: activity type is required for activity sync\n");
            exit(1);
        }
        $activityType = ActivityType::tryFrom($activityTypeValue);
        if ($activityType === null) {
            fprintf(STDERR, "Error: invalid activity type '%s'. Valid: %s\n", $activityTypeValue, implode(', ', array_column(ActivityType::cases(), 'value')));
            exit(1);
        }
        return $engine->syncActivity($recordId, $activityType);
    })(),
    default => (function () use ($entityType) {
        fprintf(STDERR, "Error: unknown entity type '%s'. Valid: contact, account, activity\n", $entityType);
        exit(1);
    })(),
};

$logger->info(sprintf(
    '%s %s: %d total, %d created, %d updated, %d failed (%.2fs)',
    ucfirst($entityType),
    $recordId,
    $result->getTotalCount(),
    $result->getCreatedCount(),
    $result->getUpdatedCount(),
    $result->getFailedCount(),
    $result->getDuration(),
));

if ($result->getFailedCount() > 0) {
    foreach ($result->getFailedRecords() as $record) {
        $logger->error(sprintf('Failed: %s', $record->errorMessage));
    }
    exit(1);
}
