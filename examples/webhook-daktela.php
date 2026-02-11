<?php

/**
 * Daktela webhook endpoint for real-time sync (Raynet <-> Daktela).
 *
 * Receives webhook events from Daktela and syncs the affected record
 * to Raynet immediately. Returns a JSON response with sync results.
 *
 * Requires the nyholm/psr7 and nyholm/psr7-server packages:
 *   composer require nyholm/psr7 nyholm/psr7-server
 *
 * Usage: Configure your web server to route webhook requests to this file.
 *
 * @see docs/06-webhooks.md in daktela/crm-sync for webhook configuration guide
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Daktela\CrmSync\Webhook\WebhookHandler;
use Daktela\CrmSync\Webhook\WebhookPayloadParser;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

// --- Create PSR-7 request from globals ---
$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();

// --- Handle the webhook ---
$handler = new WebhookHandler(
    $engine,
    new WebhookPayloadParser(),
    $syncConfig->webhookSecret,
    $logger,
);

$webhookResult = $handler->handle($request);

// --- Send JSON response ---
http_response_code($webhookResult->httpStatusCode);
header('Content-Type: application/json');
echo json_encode($webhookResult->toResponseArray(), JSON_THROW_ON_ERROR);
