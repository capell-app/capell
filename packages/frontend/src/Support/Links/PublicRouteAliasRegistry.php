<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Links;

final class PublicRouteAliasRegistry
{
    /** @var array<string, callable(): string> */
    private array $aliases = [];

    public function register(string $alias, callable $resolver): void
    {
        $alias = trim($alias);

        if ($alias === '') {
            return;
        }

        $this->aliases[$alias] = $resolver;
    }

    public function has(string $alias): bool
    {
        return array_key_exists($alias, $this->aliases);
    }

    public function resolve(string $alias): ?string
    {
        if (! $this->has($alias)) {
            return null;
        }

        $url = ($this->aliases[$alias])();

        return is_string($url) && $url !== '' ? $url : null;
    }
}
