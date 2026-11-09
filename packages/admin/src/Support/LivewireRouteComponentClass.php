<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Livewire\Compiler\CacheManager;
use Livewire\Compiler\Compiler;
use Livewire\Factory\Factory;
use Livewire\Finder\Finder;

/**
 * Resolves the class behind a `Route::livewire()` component without redeclaring single- and
 * multi-file components on every application boot.
 *
 * Livewire compiles such a component to a file that returns `new class extends Component`, and
 * resolving its name requires that file. Without OPcache (the CLI default) each require compiles
 * the file again and declares another anonymous class, which PHP never frees. The panel route
 * guard classifies every route on every boot, so a long-lived process that boots the application
 * repeatedly, such as a test worker, kept every compiled settings page declared once per boot.
 *
 * The anonymous class from the first require stays declared for the life of the process, so its
 * name remains valid while neither the source nor the compiled files have changed since.
 */
final class LivewireRouteComponentClass
{
    /** @var array<string, array{source: int, compiled: int, class: string}> */
    private static array $compiled = [];

    public static function of(object|string|null $component): ?string
    {
        if ($component === null) {
            return null;
        }

        if (is_object($component)) {
            return $component::class;
        }

        /** @var Factory $factory */
        $factory = resolve('livewire.factory');
        $sourcePath = self::compiledSourcePath($component);

        if ($sourcePath === null) {
            return $factory->resolveComponentClass($component);
        }

        /** @var Compiler $compiler */
        $compiler = resolve('livewire.compiler');
        $cache = $compiler->cacheManager;
        $known = self::$compiled[$sourcePath] ?? null;

        if ($known !== null
            && class_exists($known['class'], false)
            && self::isCurrent($cache, $sourcePath, $known['source'], $known['compiled'])) {
            return $known['class'];
        }

        $class = $factory->resolveComponentClass($component);

        if ($cache->hasBeenCompiled($sourcePath)) {
            self::$compiled[$sourcePath] = [
                'source' => $cache->getLatestFileModificationTimeOfSource($sourcePath),
                'compiled' => $cache->getLatestFileModificationTimeOfCompiledFiles($sourcePath),
                'class' => $class,
            ];
        }

        return $class;
    }

    /** The source of a single- or multi-file component, or null when Livewire resolves a class instead. */
    private static function compiledSourcePath(string $component): ?string
    {
        /** @var Finder $finder */
        $finder = resolve('livewire.finder');
        $name = $finder->normalizeName($component);

        if ($name === null || $finder->resolveClassComponentClassName($name) !== null) {
            return null;
        }

        return $finder->resolveMultiFileComponentPath($name) ?? $finder->resolveSingleFileComponentPath($name);
    }

    private static function isCurrent(CacheManager $cache, string $sourcePath, int $source, int $compiled): bool
    {
        return $cache->hasBeenCompiled($sourcePath)
            && $cache->getLatestFileModificationTimeOfSource($sourcePath) === $source
            && $cache->getLatestFileModificationTimeOfCompiledFiles($sourcePath) === $compiled;
    }
}
