<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use InvalidArgumentException;

final class ErrorPagePathResolver
{
    public function pathForDomainAndStatus(SiteDomain $siteDomain, string $status): string
    {
        $scheme = $siteDomain->getAttributes()['scheme'] ?? null;
        if ($scheme !== null) {
            $this->assertSafeSegment('scheme', $scheme);
        }

        $this->assertSafeSegment('domain', $siteDomain->domain);
        $this->assertSafePath('site domain path', $siteDomain->path ?? '/');
        $this->assertStatus($status);

        $path = sprintf('error/%s.%s', $scheme ?? 'any', $siteDomain->domain);

        if ($siteDomain->path !== null && $siteDomain->path !== '') {
            $path .= $siteDomain->path;
        }

        $identity = SiteDomainAddressing::routingIdentity($scheme, $siteDomain->domain, $siteDomain->port, $siteDomain->path);

        return rtrim($path, '/') . '/' . $identity . '/' . $status . '/index.html';
    }

    private function assertStatus(string $status): void
    {
        throw_if(preg_match('/^\d+$/', $status) !== 1, InvalidArgumentException::class, 'Unsafe status for error page path.');
    }

    private function assertSafeSegment(string $label, ?string $value): void
    {
        if ($value === null || $value === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $value) === 1 || str_contains($value, '..')) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for error page path.', $label));
        }
    }

    private function assertSafePath(string $label, string $value): void
    {
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for error page path.', $label));
        }

        if (str_starts_with($value, '//')) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for error page path.', $label));
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException(sprintf('Unsafe %s for error page path.', $label));
            }
        }
    }
}
