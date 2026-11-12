<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

use Capell\Core\Support\Http\PublicAddressPolicy;
use Illuminate\Contracts\Routing\UrlGenerator;
use ReflectionProperty;

final class PublicUrlResolver
{
    public function origin(): string
    {
        $configuredOrigin = rtrim((string) config('app.url'), '/');
        $forcedRoot = $this->forcedRoot();

        if ($forcedRoot !== null) {
            return $forcedRoot;
        }

        $origin = rtrim((string) url('/'), '/');

        if ($origin !== '' && ! $this->shouldRejectPrivateRequestOrigin($origin, $configuredOrigin)) {
            return $origin;
        }

        return $configuredOrigin;
    }

    public function to(string $path = '/'): string
    {
        $path = trim($path);

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $normalizedPath = '/' . ltrim($path, '/');

        if ($normalizedPath === '/') {
            return $this->origin();
        }

        return $this->origin() . $normalizedPath;
    }

    private function shouldRejectPrivateRequestOrigin(string $requestOrigin, string $configuredOrigin): bool
    {
        return $this->isPrivateOrigin($requestOrigin) && ! $this->isPrivateOrigin($configuredOrigin);
    }

    private function isPrivateOrigin(string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $address = trim($host, '[]');

        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            return ! resolve(PublicAddressPolicy::class)->isPublicAddress($address);
        }

        return $host === 'localhost'
            || ! str_contains($host, '.')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    private function forcedRoot(): ?string
    {
        $property = new ReflectionProperty(resolve(UrlGenerator::class), 'forcedRoot');
        $root = $property->getValue(resolve(UrlGenerator::class));

        return is_string($root) && $root !== '' ? rtrim($root, '/') : null;
    }
}
