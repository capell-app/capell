<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteDomains;

use Capell\Core\Support\SiteDomains\SiteDomainAddressing;

final readonly class SiteDomainInputData
{
    public function __construct(
        public ?string $scheme,
        public ?string $host,
        public ?int $port,
        public string $mountPath,
    ) {}

    public function persistencePath(): ?string
    {
        return $this->mountPath === '/' ? null : $this->mountPath;
    }

    public function routingIdentity(): string
    {
        return SiteDomainAddressing::routingIdentity(
            $this->scheme,
            $this->host,
            $this->port,
            $this->mountPath,
        );
    }
}
