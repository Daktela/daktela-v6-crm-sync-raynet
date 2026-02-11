<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Raynet;

final readonly class RaynetConfiguration
{
    public function __construct(
        public string $apiUrl,
        public string $email,
        public string $apiKey,
        public string $instanceName,
        public string $personType = 'person',
        public int $ownerId = 0,
    ) {
    }

    public function getPersonEndpoint(): string
    {
        return $this->personType === 'contact-person' ? 'contact-person' : 'person';
    }
}
