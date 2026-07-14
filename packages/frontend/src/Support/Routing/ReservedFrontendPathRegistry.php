<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

final class ReservedFrontendPathRegistry
{
    /** @var array<string, true> */
    private array $exactPaths = [];

    /** @var array<string, true> */
    private array $prefixes = [];

    public function reserveExact(string $path): void
    {
        $path = $this->normalize($path);

        if ($path === '') {
            return;
        }

        $this->exactPaths[$path] = true;
    }

    public function reservePrefix(string $prefix): void
    {
        $prefix = $this->normalize($prefix);

        if ($prefix === '') {
            return;
        }

        $this->prefixes[$prefix] = true;
    }

    public function isReserved(string $path): bool
    {
        $path = $this->normalize($path);

        if ($path === '') {
            return false;
        }

        if (isset($this->exactPaths[$path])) {
            return true;
        }

        return array_any(array_keys($this->prefixes), fn (string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix . '/'));
    }

    /**
     * @return array<int, string>
     */
    public function exactPaths(): array
    {
        return array_keys($this->exactPaths);
    }

    /**
     * @return array<int, string>
     */
    public function prefixes(): array
    {
        return array_keys($this->prefixes);
    }

    private function normalize(string $path): string
    {
        $path = trim($path, '/');

        return preg_replace('#/+#', '/', $path) ?? '';
    }
}
