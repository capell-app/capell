<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\View;

use Capell\Core\Octane\Resettable;
use Illuminate\View\FileViewFinder;

final class ThemeViewRegistrar implements Resettable
{
    private ?string $registeredKey = null;

    /** @var list<string> */
    private array $registeredPaths = [];

    /**
     * @param  list<string>|null  $fallbackPaths
     */
    public function __construct(
        private readonly FileViewFinder $finder,
        private ?array $fallbackPaths = null,
    ) {}

    /**
     * Register theme view paths under the `capell::` namespace.
     *
     * Paths are provided most-specific first. replaceNamespace() prevents
     * stale paths from a previous request/theme from remaining as fallbacks
     * under Octane or other long-lived workers.
     *
     * @param  list<string>  $paths  Most-specific first.
     */
    public function register(array $paths, string $themeKey): void
    {
        $appOverride = resource_path('themes/' . $themeKey);
        if ($themeKey !== '' && is_dir($appOverride)) {
            array_unshift($paths, $appOverride);
        }

        $paths = array_values(array_unique(array_filter([
            ...$paths,
            ...$this->fallbackPaths(),
        ], fn (string $path): bool => $path !== '')));

        if ($paths === $this->registeredPaths && $themeKey === $this->registeredKey) {
            return;
        }

        $this->replaceNamespace($paths);

        $this->registeredKey = $themeKey;
        $this->registeredPaths = $paths;
    }

    public function flushOctaneState(): void
    {
        $fallbackPaths = $this->fallbackPaths();

        $this->replaceNamespace($fallbackPaths);
        $this->registeredKey = null;
        $this->registeredPaths = $fallbackPaths;
    }

    /**
     * @return list<string>
     */
    private function fallbackPaths(): array
    {
        if ($this->fallbackPaths !== null) {
            return $this->fallbackPaths;
        }

        return $this->fallbackPaths = [dirname(__DIR__, 3) . '/resources/views'];
    }

    /** @param list<string> $paths */
    private function replaceNamespace(array $paths): void
    {
        // FileViewFinder caches resolved names independently from namespace
        // hints. Clear it whenever a long-lived worker changes theme chains.
        $this->finder->flush();
        $this->finder->replaceNamespace('capell', $paths);
    }
}
