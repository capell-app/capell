<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Links;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;

/** @extends AbstractKeyedRegistry<callable(): string> */
final class PublicRouteAliasRegistry extends AbstractKeyedRegistry
{
    public function register(string $alias, callable $resolver): void
    {
        $alias = trim($alias);

        if ($alias === '') {
            return;
        }

        $this->setItem($alias, $resolver);
    }

    public function has(string $alias): bool
    {
        return $this->hasItem($alias);
    }

    public function resolve(string $alias): ?string
    {
        if (! $this->has($alias)) {
            return null;
        }

        $resolver = $this->getItem($alias);

        if ($resolver === null) {
            return null;
        }

        $url = $resolver();

        return is_string($url) && $url !== '' ? $url : null;
    }
}
