<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Theme;

use Capell\Core\ThemeStudio\Contracts\ThemePageAdapter;
use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class ThemePageAdapterRegistry
{
    /** @var array<string, class-string<ThemePageAdapter>|Closure|ThemePageAdapter> */
    private array $adapters = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ThemePageAdapter>|Closure|ThemePageAdapter  $adapter
     */
    public function register(string $themeKey, string|Closure|ThemePageAdapter $adapter): void
    {
        $this->adapters[$themeKey] = $adapter;
    }

    public function adapterFor(string $themeKey, ThemePageAdapter $fallback): ThemePageAdapter
    {
        $adapter = $this->adapters[$themeKey] ?? null;

        if ($adapter === null) {
            return $fallback;
        }

        if ($adapter instanceof ThemePageAdapter) {
            return $adapter;
        }

        if ($adapter instanceof Closure) {
            $resolved = $adapter($this->container);

            if (! $resolved instanceof ThemePageAdapter) {
                throw new InvalidArgumentException(sprintf(
                    'Theme page adapter closure for [%s] must return an instance of [%s].',
                    $themeKey,
                    ThemePageAdapter::class,
                ));
            }

            return $resolved;
        }

        return $this->container->make($adapter);
    }

    public function has(string $themeKey): bool
    {
        return array_key_exists($themeKey, $this->adapters);
    }
}
