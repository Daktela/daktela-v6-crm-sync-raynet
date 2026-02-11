<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet;

use Daktela\CrmSync\Raynet\Exception\RaynetApiException;
use Daktela\CrmSync\Raynet\Exception\RaynetRateLimitException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RaynetClient
{
    private ClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(
        private readonly RaynetConfiguration $config,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => rtrim($this->config->apiUrl, '/') . '/',
            'auth' => [$this->config->email, $this->config->apiKey],
            'headers' => [
                'X-Instance-Name' => $this->config->instanceName,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $entity, int $id): ?array
    {
        try {
            $response = $this->get(sprintf('%s/%d/', $entity, $id));
        } catch (RaynetApiException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404') || str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw $e;
        }

        return $response['data'] ?? null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function findBy(string $entity, array $filters): ?array
    {
        $query = [];
        foreach ($filters as $field => $value) {
            $query[$field . '[EQ]'] = $value;
        }
        $query['limit'] = 1;

        try {
            $response = $this->get($entity . '/', $query);
        } catch (RaynetApiException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404') || str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw $e;
        }

        $data = $response['data'] ?? [];

        return $data[0] ?? null;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(string $entity, int $limit = 100): \Generator
    {
        $offset = 0;

        do {
            $response = $this->get($entity . '/', [
                'offset' => $offset,
                'limit' => $limit,
            ]);

            $data = $response['data'] ?? [];
            $totalCount = $response['totalCount'] ?? 0;

            foreach ($data as $record) {
                yield $record;
            }

            $offset += $limit;
        } while ($offset < $totalCount);
    }

    /**
     * Raynet uses PUT for creation.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $entity, array $data): array
    {
        return $this->put($entity . '/', $data);
    }

    /**
     * Raynet uses POST for update.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $entity, int $id, array $data): array
    {
        return $this->post(sprintf('%s/%d/', $entity, $id), $data);
    }

    /**
     * Find a record by its external ID using the /ext/ endpoint.
     *
     * @return array<string, mixed>|null
     */
    public function findByExtId(string $entity, string $extId): ?array
    {
        try {
            $response = $this->get(sprintf('%s/ext/%s/', $entity, $extId));
        } catch (RaynetApiException $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw $e;
        }

        return $response['data'] ?? null;
    }

    /**
     * Set an external ID on an existing record.
     */
    public function setExtId(string $entity, int $id, string $extId): void
    {
        $this->put(sprintf('%s/%d/extId/', $entity, $id), ['extId' => $extId]);
    }

    public function ping(): bool
    {
        try {
            $this->get('company/', ['limit' => 1]);

            return true;
        } catch (RaynetApiException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $uri, array $query = []): array
    {
        try {
            $this->logger->debug('Raynet GET {uri}', ['uri' => $uri, 'query' => $query]);

            $response = $this->httpClient->request('GET', $uri, [
                'query' => $query,
            ]);

            return $this->parseResponse((string) $response->getBody(), $response->getStatusCode());
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $this->checkRateLimit($e);
            $response = $e->getResponse();

            return $this->parseResponse((string) $response->getBody(), $response->getStatusCode());
        } catch (GuzzleException $e) {
            throw RaynetApiException::connectionFailed($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function put(string $uri, array $data): array
    {
        try {
            $this->logger->debug('Raynet PUT {uri}', ['uri' => $uri]);

            $response = $this->httpClient->request('PUT', $uri, [
                'json' => $data,
            ]);

            return $this->parseResponse((string) $response->getBody(), $response->getStatusCode());
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $this->checkRateLimit($e);
            $response = $e->getResponse();

            return $this->parseResponse((string) $response->getBody(), $response->getStatusCode());
        } catch (GuzzleException $e) {
            throw RaynetApiException::connectionFailed($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function post(string $uri, array $data): array
    {
        try {
            $this->logger->debug('Raynet POST {uri}', ['uri' => $uri]);

            $response = $this->httpClient->request('POST', $uri, [
                'json' => $data,
            ]);

            return $this->parseResponse((string) $response->getBody(), $response->getStatusCode());
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $this->checkRateLimit($e);
            $response = $e->getResponse();

            return $this->parseResponse((string) $response->getBody(), $response->getStatusCode());
        } catch (GuzzleException $e) {
            throw RaynetApiException::connectionFailed($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(string $body, int $statusCode): array
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);

        if ($decoded === null) {
            throw RaynetApiException::fromResponse($statusCode, $body);
        }

        if ($statusCode >= 400) {
            throw RaynetApiException::fromResponse($statusCode, $body);
        }

        if (isset($decoded['success']) && $decoded['success'] === false) {
            throw RaynetApiException::fromResponse($statusCode, $body);
        }

        return $decoded;
    }

    private function checkRateLimit(GuzzleException $e): void
    {
        if ($e instanceof \GuzzleHttp\Exception\ClientException && $e->getResponse()->getStatusCode() === 429) {
            throw RaynetRateLimitException::dailyLimitReached();
        }
    }
}
