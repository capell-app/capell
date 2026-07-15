<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

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

        if ($origin !== '') {
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

    private function forcedRoot(): ?string
    {
        $property = new ReflectionProperty(resolve(UrlGenerator::class), 'forcedRoot');
        $root = $property->getValue(resolve(UrlGenerator::class));

        return is_string($root) && $root !== '' ? rtrim($root, '/') : null;
    }
}
