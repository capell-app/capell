<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Maintenance;

use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use InvalidArgumentException;

final class MaintenancePagePathResolver
{
    public function pathForDomain(SiteDomain $siteDomain): string
    {
        $scheme = $siteDomain->getAttributes()['scheme'] ?? null;
        if ($scheme !== null) {
            $this->assertSafeSegment('scheme', $scheme);
        }

        $this->assertSafeSegment('domain', $siteDomain->domain);
        $this->assertSafePath('site domain path', $siteDomain->path ?? '/');

        $path = sprintf('maintenance/%s.%s', $scheme ?? 'any', $siteDomain->domain);

        if ($siteDomain->path !== null && $siteDomain->path !== '') {
            $path .= $siteDomain->path;
        }

        $identity = SiteDomainAddressing::routingIdentity($scheme, $siteDomain->domain, $siteDomain->port, $siteDomain->path);

        return rtrim($path, '/') . '/' . $identity . '/index.html';
    }

    private function assertSafeSegment(string $label, ?string $value): void
    {
        if ($value === null || $value === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $value) === 1 || str_contains($value, '..')) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for maintenance page path.', $label));
        }
    }

    private function assertSafePath(string $label, string $value): void
    {
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for maintenance page path.', $label));
        }

        if (str_starts_with($value, '//')) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for maintenance page path.', $label));
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException(sprintf('Unsafe %s for maintenance page path.', $label));
            }
        }
    }
}
