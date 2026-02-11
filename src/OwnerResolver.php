<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves a Daktela user to a Raynet person (owner) ID.
 *
 * Lookup strategy:
 *  1. Search by ownerEmail (user.emailAuth) → contactInfo.email
 *  2. Search by ownerLogin (user.name) as email → contactInfo.email (if it contains @)
 *  3. Fall back to the configured default owner ID
 *
 * Results are cached per email for the lifetime of the object (one sync run).
 */
final class OwnerResolver
{
    /** @var array<string, int|null> lookup key → person ID (null = not found) */
    private array $cache = [];

    public function __construct(
        private readonly RaynetClient $client,
        private readonly RaynetConfiguration $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Resolve owner from email and/or login username.
     *
     * @param string|null $email  The user's authentication email (user.emailAuth)
     * @param string|null $login  The user's login name (user.name) — may also be an email
     */
    public function resolve(?string $email, ?string $login = null): int
    {
        // Try email first
        if ($email !== null && $email !== '') {
            $personId = $this->lookupCached($email);
            if ($personId !== null) {
                return $personId;
            }
        }

        // Try login as email (many systems use email as username)
        if ($login !== null && $login !== '' && str_contains($login, '@') && $login !== $email) {
            $personId = $this->lookupCached($login);
            if ($personId !== null) {
                return $personId;
            }
        }

        $this->logger->debug('Owner not found in Raynet (email={email}, login={login}), using default {id}', [
            'email' => $email,
            'login' => $login,
            'id' => $this->config->ownerId,
        ]);

        return $this->config->ownerId;
    }

    private function lookupCached(string $email): ?int
    {
        if (array_key_exists($email, $this->cache)) {
            return $this->cache[$email];
        }

        $personId = $this->lookupByEmail($email);
        $this->cache[$email] = $personId;

        if ($personId !== null) {
            $this->logger->debug('Resolved owner {email} → person {id}', ['email' => $email, 'id' => $personId]);
        }

        return $personId;
    }

    private function lookupByEmail(string $email): ?int
    {
        try {
            $record = $this->client->findBy('person', ['contactInfo.email' => $email]);
            if ($record !== null && isset($record['id'])) {
                return (int) $record['id'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Owner lookup failed for {email}: {error}', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
