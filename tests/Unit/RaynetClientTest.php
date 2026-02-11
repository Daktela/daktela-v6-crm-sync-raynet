<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet\Tests\Unit;

use Daktela\CrmSync\Raynet\Exception\RaynetApiException;
use Daktela\CrmSync\Raynet\Exception\RaynetRateLimitException;
use Daktela\CrmSync\Raynet\RaynetClient;
use Daktela\CrmSync\Raynet\RaynetConfiguration;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RaynetClientTest extends TestCase
{
    private RaynetConfiguration $config;

    protected function setUp(): void
    {
        $this->config = new RaynetConfiguration(
            apiUrl: 'https://app.raynet.cz/api/v2/',
            email: 'user@example.com',
            apiKey: 'test-api-key',
            instanceName: 'test-instance',
        );
    }

    #[Test]
    public function pingReturnsTrueOnSuccess(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'success' => true,
                'totalCount' => 1,
                'data' => [['id' => 1, 'name' => 'Test']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        self::assertTrue($client->ping());
    }

    #[Test]
    public function pingReturnsFalseOnError(): void
    {
        $client = $this->createClient([
            new Response(500, [], json_encode([
                'success' => false,
            ], JSON_THROW_ON_ERROR)),
        ]);

        self::assertFalse($client->ping());
    }

    #[Test]
    public function findReturnsRecordOnSuccess(): void
    {
        $record = ['id' => 42, 'firstName' => 'John', 'lastName' => 'Doe'];
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'success' => true,
                'data' => $record,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $client->find('person', 42);

        self::assertSame($record, $result);
    }

    #[Test]
    public function findReturnsNullOn404(): void
    {
        $client = $this->createClient([
            new Response(404, [], json_encode([
                'success' => false,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $client->find('person', 999);

        self::assertNull($result);
    }

    #[Test]
    public function findByReturnsFirstMatch(): void
    {
        $record = ['id' => 1, 'email' => 'john@example.com'];
        $history = [];
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'success' => true,
                'totalCount' => 1,
                'data' => [$record],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $result = $client->findBy('person', ['email' => 'john@example.com']);

        self::assertSame($record, $result);

        // Verify query parameter format
        $request = $history[0]['request'];
        $query = $request->getUri()->getQuery();
        self::assertStringContainsString('email%5BEQ%5D=john%40example.com', $query);
        self::assertStringContainsString('limit=1', $query);
    }

    #[Test]
    public function findByReturnsNullWhenNoMatch(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'success' => true,
                'totalCount' => 0,
                'data' => [],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $client->findBy('person', ['email' => 'nobody@example.com']);

        self::assertNull($result);
    }

    #[Test]
    public function iterateYieldsAllRecordsAcrossPages(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'success' => true,
                'totalCount' => 3,
                'data' => [
                    ['id' => 1, 'name' => 'First'],
                    ['id' => 2, 'name' => 'Second'],
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'success' => true,
                'totalCount' => 3,
                'data' => [
                    ['id' => 3, 'name' => 'Third'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $records = iterator_to_array($client->iterate('company', 2));

        self::assertCount(3, $records);
        self::assertSame(1, $records[0]['id']);
        self::assertSame(3, $records[2]['id']);
    }

    #[Test]
    public function createUsesPutMethod(): void
    {
        $history = [];
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'success' => true,
                'data' => ['id' => 10],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $client->create('task', ['subject' => 'Test Task']);

        self::assertSame('PUT', $history[0]['request']->getMethod());
    }

    #[Test]
    public function updateUsesPostMethod(): void
    {
        $history = [];
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'success' => true,
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $client->update('task', 10, ['subject' => 'Updated']);

        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertStringContainsString('task/10/', (string) $history[0]['request']->getUri());
    }

    #[Test]
    public function itSendsAuthHeaders(): void
    {
        $history = [];
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'success' => true,
                'totalCount' => 0,
                'data' => [],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $client->ping();

        $request = $history[0]['request'];
        $auth = $request->getHeader('Authorization');
        self::assertNotEmpty($auth);
        self::assertStringStartsWith('Basic ', $auth[0]);

        $instanceName = $request->getHeader('X-Instance-Name');
        self::assertSame(['test-instance'], $instanceName);
    }

    #[Test]
    public function itThrowsRateLimitExceptionOn429(): void
    {
        $mock = new MockHandler([
            new \GuzzleHttp\Exception\ClientException(
                'Rate limited',
                new \GuzzleHttp\Psr7\Request('GET', 'test'),
                new Response(429, [], 'Rate limit exceeded'),
            ),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client([
            'handler' => $handlerStack,
            'base_uri' => $this->config->apiUrl,
            'auth' => [$this->config->email, $this->config->apiKey],
            'headers' => ['X-Instance-Name' => $this->config->instanceName],
        ]);

        $client = new RaynetClient($this->config, $httpClient);

        $this->expectException(RaynetRateLimitException::class);
        $client->findBy('company', ['name' => 'test']);
    }

    #[Test]
    public function itThrowsOnApiErrorResponse(): void
    {
        $client = $this->createClient([
            new Response(500, [], json_encode([
                'success' => false,
                'error' => 'Internal error',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(RaynetApiException::class);
        $client->find('person', 1);
    }

    /**
     * @param list<Response|\GuzzleHttp\Exception\GuzzleException> $responses
     * @param list<array<string, mixed>> $history
     */
    private function createClient(array $responses, array &$history = []): RaynetClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $httpClient = new Client([
            'handler' => $handlerStack,
            'base_uri' => $this->config->apiUrl,
            'auth' => [$this->config->email, $this->config->apiKey],
            'headers' => [
                'X-Instance-Name' => $this->config->instanceName,
                'Content-Type' => 'application/json',
            ],
        ]);

        return new RaynetClient($this->config, $httpClient);
    }
}
