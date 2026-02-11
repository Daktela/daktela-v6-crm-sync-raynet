<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet;

use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Exception\AdapterException;
use Psr\Log\LoggerInterface;

final class RaynetCrmAdapter implements CrmAdapterInterface
{
    /**
     * Maps Daktela ActivityType values to Raynet API endpoint names.
     * call → call, email → email, all others → task
     */
    /** @var array<string, string> */
    private const ACTIVITY_ENDPOINT_MAP = [
        'call' => 'phoneCall',
        'email' => 'email',
        'web' => 'task',
        'sms' => 'task',
        'fbm' => 'task',
        'wap' => 'task',
        'vbr' => 'task',
    ];

    /** @var list<string> */
    private const ACTIVITY_ENDPOINTS = ['phoneCall', 'email', 'task'];

    private readonly OwnerResolver $ownerResolver;

    public function __construct(
        private readonly RaynetClient $client,
        private readonly RaynetConfiguration $config,
        private readonly LoggerInterface $logger,
        ?OwnerResolver $ownerResolver = null,
    ) {
        $this->ownerResolver = $ownerResolver ?? new OwnerResolver($client, $config, $logger);
    }

    public function findContact(string $id): ?Contact
    {
        $this->logger->debug('Finding Raynet contact {id}', ['id' => $id]);

        $record = $this->client->find($this->config->getPersonEndpoint(), (int) $id);
        if ($record === null) {
            return null;
        }

        return $this->mapRaynetToContact($record);
    }

    public function findContactByLookup(string $field, string $value): ?Contact
    {
        $this->logger->debug('Finding Raynet contact by {field}={value}', ['field' => $field, 'value' => $value]);

        $record = $this->client->findBy($this->config->getPersonEndpoint(), [$field => $value]);
        if ($record === null) {
            return null;
        }

        return $this->mapRaynetToContact($record);
    }

    /** @return \Generator<int, Contact> */
    public function iterateContacts(?\DateTimeImmutable $since = null): \Generator
    {
        $this->logger->info('Iterating Raynet contacts');

        foreach ($this->client->iterate($this->config->getPersonEndpoint()) as $record) {
            yield $this->mapRaynetToContact($record);
        }
    }

    public function findAccount(string $id): ?Account
    {
        $this->logger->debug('Finding Raynet account {id}', ['id' => $id]);

        $record = $this->client->find('company', (int) $id);
        if ($record === null) {
            return null;
        }

        return $this->mapRaynetToAccount($record);
    }

    public function findAccountByLookup(string $field, string $value): ?Account
    {
        $this->logger->debug('Finding Raynet account by {field}={value}', ['field' => $field, 'value' => $value]);

        $record = $this->client->findBy('company', [$field => $value]);
        if ($record === null) {
            return null;
        }

        return $this->mapRaynetToAccount($record);
    }

    /** @return \Generator<int, Account> */
    public function iterateAccounts(?\DateTimeImmutable $since = null): \Generator
    {
        $this->logger->info('Iterating Raynet accounts');

        foreach ($this->client->iterate('company') as $record) {
            yield $this->mapRaynetToAccount($record);
        }
    }

    public function findActivity(string $id): ?Activity
    {
        $this->logger->debug('Finding Raynet activity {id}', ['id' => $id]);

        foreach (self::ACTIVITY_ENDPOINTS as $endpoint) {
            $record = $this->client->find($endpoint, (int) $id);
            if ($record !== null) {
                return $this->mapRaynetToActivity($record, $endpoint);
            }
        }

        return null;
    }

    public function findActivityByLookup(string $field, string $value): ?Activity
    {
        $this->logger->debug('Finding Raynet activity by {field}={value}', ['field' => $field, 'value' => $value]);

        // Use the /ext/ endpoint for externalId lookups
        if ($field === 'externalId') {
            foreach (self::ACTIVITY_ENDPOINTS as $endpoint) {
                $record = $this->client->findByExtId($endpoint, $value);
                if ($record !== null) {
                    return $this->mapRaynetToActivity($record, $endpoint);
                }
            }

            return null;
        }

        foreach (self::ACTIVITY_ENDPOINTS as $endpoint) {
            $record = $this->client->findBy($endpoint, [$field => $value]);
            if ($record !== null) {
                return $this->mapRaynetToActivity($record, $endpoint);
            }
        }

        return null;
    }

    public function createActivity(Activity $activity): Activity
    {
        $endpoint = $this->resolveActivityEndpoint($activity);
        $payload = $this->buildActivityPayload($activity);

        $this->logger->info('Creating Raynet {endpoint} activity', ['endpoint' => $endpoint]);

        try {
            $response = $this->client->create($endpoint, $payload);
        } catch (\Throwable $e) {
            throw AdapterException::createFailed('activity', $e, $e->getMessage());
        }

        $data = $response['data'] ?? $response;
        $id = (string) ($data['id'] ?? $response['id'] ?? '');

        // Set external ID via separate endpoint if provided
        $extId = $activity->get('externalId');
        if ($extId !== null && $extId !== '' && $id !== '') {
            try {
                $this->client->setExtId($endpoint, (int) $id, (string) $extId);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to set extId on {endpoint}/{id}', [
                    'endpoint' => $endpoint,
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new Activity($id, $activity->getData(), $activity->getActivityType());
    }

    public function updateActivity(string $id, Activity $activity): Activity
    {
        $endpoint = $this->resolveActivityEndpoint($activity);
        $payload = $this->buildActivityPayload($activity, isUpdate: true);

        $this->logger->info('Updating Raynet {endpoint} activity {id}', ['endpoint' => $endpoint, 'id' => $id]);

        try {
            $this->client->update($endpoint, (int) $id, $payload);
        } catch (\Throwable $e) {
            throw AdapterException::updateFailed('activity', $id, $e, $e->getMessage());
        }

        return new Activity($id, $activity->getData(), $activity->getActivityType());
    }

    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $lookupValue = $activity->get($lookupField);
        if ($lookupValue === null) {
            throw AdapterException::missingId('activity');
        }

        $existing = $this->findActivityByLookup($lookupField, (string) $lookupValue);

        if ($existing !== null && $existing->getId() !== null) {
            return $this->updateActivity($existing->getId(), $activity);
        }

        return $this->createActivity($activity);
    }

    public function ping(): bool
    {
        return $this->client->ping();
    }

    /**
     * @param array<string, mixed> $record
     */
    private function mapRaynetToContact(array $record): Contact
    {
        $id = isset($record['id']) ? (string) $record['id'] : null;

        $firstName = (string) ($record['firstName'] ?? '');
        $lastName = (string) ($record['lastName'] ?? '');
        $fullName = trim($firstName . ' ' . $lastName);

        // contactInfo is at top level in both list and detail endpoints
        $contactInfo = $record['contactInfo'] ?? [];

        $data = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'fullName' => $fullName,
            'email' => (string) ($contactInfo['email'] ?? ''),
            'tel1' => (string) ($contactInfo['tel1'] ?? ''),
        ];

        // Company is under primaryRelationship.company
        $companyId = $record['primaryRelationship']['company']['id'] ?? null;
        if ($companyId !== null) {
            $data['company_id'] = (string) $companyId;
        }

        if (isset($record['customFields']) && is_array($record['customFields'])) {
            $data['customFields'] = $record['customFields'];
        }

        return new Contact($id, $data);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function mapRaynetToAccount(array $record): Account
    {
        $id = isset($record['id']) ? (string) $record['id'] : null;

        $contactInfo = $record['primaryAddress']['contactInfo'] ?? [];
        $address = $record['primaryAddress']['address'] ?? [];

        $data = [
            'name' => (string) ($record['name'] ?? ''),
            'regNumber' => (string) ($record['regNumber'] ?? ''),
            'email' => (string) ($contactInfo['email'] ?? ''),
            'tel1' => (string) ($contactInfo['tel1'] ?? ''),
            'street' => (string) ($address['street'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'zipCode' => (string) ($address['zipCode'] ?? ''),
            'country' => (string) ($address['country'] ?? ''),
        ];

        if (isset($record['customFields']) && is_array($record['customFields'])) {
            $data['customFields'] = $record['customFields'];
        }

        return new Account($id, $data);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function mapRaynetToActivity(array $record, string $endpoint): Activity
    {
        $id = isset($record['id']) ? (string) $record['id'] : null;

        $activityType = match ($endpoint) {
            'phoneCall' => ActivityType::Call,
            'email' => ActivityType::Email,
            default => null,
        };

        // extIds is an array in Raynet, take the first one as our externalId
        $extIds = $record['extIds'] ?? [];
        $externalId = is_array($extIds) && $extIds !== [] ? $extIds[0] : null;

        $data = [
            'subject' => (string) ($record['subject'] ?? $record['title'] ?? ''),
            'scheduledFrom' => $record['scheduledFrom'] ?? null,
            'scheduledTill' => $record['scheduledTill'] ?? null,
            'externalId' => $externalId,
        ];

        if (isset($record['customFields']) && is_array($record['customFields'])) {
            $data['customFields'] = $record['customFields'];
        }

        return new Activity($id, $data, $activityType);
    }

    private function resolvePersonCompany(int $personId): ?int
    {
        try {
            $record = $this->client->find($this->config->getPersonEndpoint(), $personId);
            if ($record === null) {
                return null;
            }

            $companyId = $record['primaryRelationship']['company']['id'] ?? null;

            return $companyId !== null ? (int) $companyId : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve company for person {id}', [
                'id' => $personId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveActivityEndpoint(Activity $activity): string
    {
        $type = $activity->getActivityType();
        if ($type === null) {
            return 'task';
        }

        return self::ACTIVITY_ENDPOINT_MAP[$type->value];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildActivityPayload(Activity $activity, bool $isUpdate = false): array
    {
        $data = $activity->getData();

        $payload = [];

        if (isset($data['subject'])) {
            $payload['subject'] = $data['subject'];
            // Raynet requires 'title' on phoneCall/task — use subject as fallback
            $payload['title'] = $data['subject'];
        }
        if (isset($data['title'])) {
            $payload['title'] = $data['title'];
        }
        if (isset($data['scheduledFrom'])) {
            $payload['scheduledFrom'] = $data['scheduledFrom'];
            // Raynet tasks require 'deadline' — use scheduledFrom as fallback
            $payload['deadline'] = $data['scheduledFrom'];
        }
        if (isset($data['scheduledTill'])) {
            $payload['scheduledTill'] = $data['scheduledTill'];
        }
        if (isset($data['description'])) {
            $payload['description'] = $data['description'];
        }
        if (isset($data['customFields']) && is_array($data['customFields'])) {
            $payload['customFields'] = $data['customFields'];
        }

        // Resolve owner from mapped email/login, fall back to config default
        if (!$isUpdate) {
            $ownerEmail = $data['ownerEmail'] ?? null;
            $ownerLogin = $data['ownerLogin'] ?? null;
            $payload['owner'] = $this->ownerResolver->resolve(
                is_string($ownerEmail) ? $ownerEmail : null,
                is_string($ownerLogin) ? $ownerLogin : null,
            );
        }

        // Link activity to Raynet person via contacts array, and resolve their company
        // Only use contactPersonId if it's a valid positive integer (skip non-Raynet contacts)
        $contactPersonId = $data['contactPersonId'] ?? null;
        if ($contactPersonId !== null && $contactPersonId !== '' && ctype_digit((string) $contactPersonId) && (int) $contactPersonId > 0 && !$isUpdate) {
            $payload['contacts'] = [(int) $contactPersonId];

            // Look up the person's company to set on the activity
            $companyId = $this->resolvePersonCompany((int) $contactPersonId);
            if ($companyId !== null) {
                $payload['company'] = $companyId;
            }
        }

        // Past activities should be marked as completed
        $payload['status'] = 'COMPLETED';

        return $payload;
    }
}
