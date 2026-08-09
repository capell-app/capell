<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteDomains;

use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use InvalidArgumentException;

final readonly class SiteOriginData
{
    public string $scheme;

    public string $host;

    public int $effectivePort;

    public function __construct(string $scheme, string $host, int $effectivePort)
    {
        $normalizedScheme = SiteDomainAddressing::normalizeScheme($scheme);
        $normalizedHost = SiteDomainAddressing::normalizeHost($host);

        throw_if($normalizedScheme === null || $normalizedHost === null, InvalidArgumentException::class, 'A site origin requires a scheme and host.');

        throw_if($effectivePort < 1 || $effectivePort > 65535, InvalidArgumentException::class, 'A site origin port must be between 1 and 65535.');

        $this->scheme = $normalizedScheme;
        $this->host = $normalizedHost;
        $this->effectivePort = $effectivePort;
    }

    public function authority(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $defaultPort = SiteDomainAddressing::defaultPort($this->scheme);

        return $host . ($defaultPort === $this->effectivePort ? '' : ':' . $this->effectivePort);
    }

    public function rootUrl(): string
    {
        return $this->scheme . '://' . $this->authority();
    }

    public function key(): string
    {
        return hash('sha256', $this->scheme . '|' . $this->host . '|' . $this->effectivePort);
    }
}
