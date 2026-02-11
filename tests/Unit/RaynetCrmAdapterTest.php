<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Tests\Unit;

use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Raynet\RaynetClient;
use Daktela\CrmSync\Raynet\RaynetConfiguration;
use Daktela\CrmSync\Raynet\RaynetCrmAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RaynetCrmAdapterTest extends TestCase
{
    private RaynetClient&MockObject $client;
    private RaynetConfiguration $config;
    private RaynetCrmAdapter $adapter;

    protected function setUp(): void
    {
        $this->client = $this->createMock(RaynetClient::class);
        $this->config = new RaynetConfiguration(
            apiUrl: 'https://app.raynet.cz/api/v2/',
            email: 'user@example.com',
            apiKey: 'key',
            instanceName: 'instance',
            personType: 'person',
        );
        $this->adapter = new RaynetCrmAdapter(
            $this->client,
            $this->config,
            new NullLogger(),
        );
    }

    #[Test]
    public function findContactMapsNestedRaynetStructure(): void
    {
        $this->client->method('find')
            ->with('person', 42)
            ->willReturn([
                'id' => 42,
                'firstName' => 'John',
                'lastName' => 'Doe',
                'contactInfo' => [
                    'email' => 'john@example.com',
                    'tel1' => '+420123456789',
                ],
                'primaryRelationship' => ['company' => ['id' => 10]],
                'customFields' => ['cf_1' => 'custom-value'],
            ]);

        $contact = $this->adapter->findContact('42');

        self::assertNotNull($contact);
        self::assertSame('42', $contact->getId());
        self::assertSame('John', $contact->get('firstName'));
        self::assertSame('Doe', $contact->get('lastName'));
        self::assertSame('John Doe', $contact->get('fullName'));
        self::assertSame('john@example.com', $contact->get('email'));
        self::assertSame('+420123456789', $contact->get('tel1'));
        self::assertSame('10', $contact->get('company_id'));
        self::assertSame(['cf_1' => 'custom-value'], $contact->get('customFields'));
    }

    #[Test]
    public function findContactReturnsNullWhenNotFound(): void
    {
        $this->client->method('find')
            ->willReturn(null);

        self::assertNull($this->adapter->findContact('999'));
    }

    #[Test]
    public function findAccountMapsCompanyStructure(): void
    {
        $this->client->method('find')
            ->with('company', 10)
            ->willReturn([
                'id' => 10,
                'name' => 'Acme Corp',
                'regNumber' => '12345678',
                'primaryAddress' => [
                    'address' => [
                        'street' => 'Main Street 1',
                        'city' => 'Prague',
                        'zipCode' => '11000',
                        'country' => 'CZ',
                    ],
                    'contactInfo' => [
                        'email' => 'info@acme.cz',
                        'tel1' => '+420111222333',
                    ],
                ],
                'customFields' => ['cf_vat' => 'CZ12345678'],
            ]);

        $account = $this->adapter->findAccount('10');

        self::assertNotNull($account);
        self::assertSame('10', $account->getId());
        self::assertSame('Acme Corp', $account->get('name'));
        self::assertSame('12345678', $account->get('regNumber'));
        self::assertSame('info@acme.cz', $account->get('email'));
        self::assertSame('+420111222333', $account->get('tel1'));
        self::assertSame('Main Street 1', $account->get('street'));
        self::assertSame('Prague', $account->get('city'));
        self::assertSame('11000', $account->get('zipCode'));
        self::assertSame('CZ', $account->get('country'));
    }

    #[Test]
    public function iterateContactsYieldsMappedContacts(): void
    {
        $this->client->method('iterate')
            ->with('person')
            ->willReturn($this->generateRecords([
                ['id' => 1, 'firstName' => 'Alice', 'lastName' => 'A', 'contactInfo' => []],
                ['id' => 2, 'firstName' => 'Bob', 'lastName' => 'B', 'contactInfo' => []],
            ]));

        $contacts = iterator_to_array($this->adapter->iterateContacts());

        self::assertCount(2, $contacts);
        self::assertSame('Alice A', $contacts[0]->get('fullName'));
        self::assertSame('Bob B', $contacts[1]->get('fullName'));
    }

    #[Test]
    public function createActivityDispatchesCallToCallEndpoint(): void
    {
        $activity = new Activity(null, ['subject' => 'Test call'], ActivityType::Call);

        $this->client->expects(self::once())
            ->method('create')
            ->with('phoneCall', self::callback(static fn (array $data): bool => $data['subject'] === 'Test call'))
            ->willReturn(['data' => ['id' => 100]]);

        $result = $this->adapter->createActivity($activity);

        self::assertSame('100', $result->getId());
    }

    #[Test]
    public function createActivityDispatchesEmailToEmailEndpoint(): void
    {
        $activity = new Activity(null, ['subject' => 'Test email'], ActivityType::Email);

        $this->client->expects(self::once())
            ->method('create')
            ->with('email', self::anything())
            ->willReturn(['data' => ['id' => 101]]);

        $result = $this->adapter->createActivity($activity);

        self::assertSame('101', $result->getId());
    }

    #[Test]
    public function createActivityDispatchesChatToTaskEndpoint(): void
    {
        $activity = new Activity(null, ['subject' => 'Web chat'], ActivityType::Chat);

        $this->client->expects(self::once())
            ->method('create')
            ->with('task', self::anything())
            ->willReturn(['data' => ['id' => 102]]);

        $result = $this->adapter->createActivity($activity);

        self::assertSame('102', $result->getId());
    }

    #[Test]
    public function createActivityDispatchesSmsToTaskEndpoint(): void
    {
        $activity = new Activity(null, ['subject' => 'SMS'], ActivityType::Sms);

        $this->client->expects(self::once())
            ->method('create')
            ->with('task', self::anything())
            ->willReturn(['data' => ['id' => 103]]);

        $this->adapter->createActivity($activity);
    }

    #[Test]
    public function upsertActivityCreatesWhenNotFound(): void
    {
        $activity = new Activity(null, ['externalId' => 'ext-1', 'subject' => 'New'], ActivityType::Call);

        $this->client->method('findByExtId')
            ->willReturn(null);

        $this->client->expects(self::once())
            ->method('create')
            ->with('phoneCall', self::anything())
            ->willReturn(['data' => ['id' => 200]]);

        $result = $this->adapter->upsertActivity('externalId', $activity);

        self::assertSame('200', $result->getId());
    }

    #[Test]
    public function upsertActivityUpdatesWhenFound(): void
    {
        $activity = new Activity(null, ['externalId' => 'ext-1', 'subject' => 'Updated'], ActivityType::Call);

        // findActivityByLookup uses findByExtId for externalId — return from phoneCall
        $this->client->method('findByExtId')
            ->willReturnCallback(static function (string $entity, string $extId): ?array {
                if ($entity === 'phoneCall') {
                    return ['id' => 50, 'subject' => 'Old', 'extIds' => ['ext-1']];
                }

                return null;
            });

        $this->client->expects(self::once())
            ->method('update')
            ->with('phoneCall', 50, self::anything())
            ->willReturn(['success' => true]);

        $result = $this->adapter->upsertActivity('externalId', $activity);

        self::assertSame('50', $result->getId());
    }

    #[Test]
    public function usesContactPersonEndpointWhenConfigured(): void
    {
        $config = new RaynetConfiguration(
            apiUrl: 'https://app.raynet.cz/api/v2/',
            email: 'user@example.com',
            apiKey: 'key',
            instanceName: 'instance',
            personType: 'contact-person',
        );
        $adapter = new RaynetCrmAdapter($this->client, $config, new NullLogger());

        $this->client->expects(self::once())
            ->method('find')
            ->with('contact-person', 1)
            ->willReturn(['id' => 1, 'firstName' => 'Test', 'lastName' => 'User', 'contactInfo' => []]);

        $adapter->findContact('1');
    }

    #[Test]
    public function pingDelegatesToClient(): void
    {
        $this->client->method('ping')->willReturn(true);

        self::assertTrue($this->adapter->ping());
    }

    #[Test]
    public function findActivityTriesAllEndpoints(): void
    {
        $calls = [];
        $this->client->method('find')
            ->willReturnCallback(static function (string $entity, int $id) use (&$calls): ?array {
                $calls[] = $entity;
                if ($entity === 'task') {
                    return ['id' => $id, 'subject' => 'Found in tasks'];
                }

                return null;
            });

        $activity = $this->adapter->findActivity('5');

        self::assertNotNull($activity);
        self::assertSame(['phoneCall', 'email', 'task'], $calls);
        self::assertSame('Found in tasks', $activity->get('subject'));
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return \Generator<int, array<string, mixed>>
     */
    private function generateRecords(array $records): \Generator
    {
        foreach ($records as $record) {
            yield $record;
        }
    }
}
