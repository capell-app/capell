<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use InvalidArgumentException;

/**
 * npm modules whose default export is a Vite plugin factory the application build must call, such as
 * `@vitejs/plugin-vue` for a package that ships `.vue` entry points. The build resolves each module from
 * the application's own node_modules and calls the factory with no arguments.
 *
 * @extends AbstractKeyedRegistry<string>
 */
final class FrontendVitePluginRegistry extends AbstractKeyedRegistry
{
    private const string PackageNamePattern = '/^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/';

    public function register(string $module, string $package): void
    {
        throw_unless(preg_match(self::PackageNamePattern, $module) === 1, InvalidArgumentException::class, 'Frontend Vite plugins must be npm package names, such as @vitejs/plugin-vue.');

        $this->setItem($package . '|' . $module, $module);
    }

    /** @return list<string> */
    public function all(): array
    {
        $modules = array_values(array_unique($this->allItems()));
        sort($modules);

        return $modules;
    }
}
