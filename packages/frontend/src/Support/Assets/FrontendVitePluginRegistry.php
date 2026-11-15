<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use InvalidArgumentException;
use JsonException;

/**
 * npm modules whose default export is a Vite plugin factory the application build must call, such as
 * `@vitejs/plugin-vue` for a package that ships `.vue` entry points. The build resolves each module from
 * the application's own node_modules and calls the factory with the registered JSON options.
 *
 * @extends AbstractKeyedRegistry<array{module: string, options: array<string, mixed>}>
 */
final class FrontendVitePluginRegistry extends AbstractKeyedRegistry
{
    private const string PackageNamePattern = '/^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/';

    /** @param  array<string, mixed>  $options  JSON-encodable options passed to the plugin factory */
    public function register(string $module, string $package, array $options = []): void
    {
        throw_unless(preg_match(self::PackageNamePattern, $module) === 1, InvalidArgumentException::class, 'Frontend Vite plugins must be npm package names, such as @vitejs/plugin-vue.');
        throw_if($options !== [] && array_is_list($options), InvalidArgumentException::class, 'Frontend Vite plugin options must be an object of named options.');

        try {
            json_encode($options, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException('Frontend Vite plugin options must be JSON-encodable.', $jsonException->getCode(), previous: $jsonException);
        }

        foreach ($this->allItems() as $existing) {
            // One build calls each plugin once, so two packages cannot ask for it with different options.
            throw_if(
                $existing['module'] === $module && self::normalised($existing['options']) !== self::normalised($options),
                InvalidArgumentException::class,
                sprintf('The Vite plugin %s is already registered with different options.', $module),
            );
        }

        $this->setItem($package . '|' . $module, ['module' => $module, 'options' => $options]);
    }

    /** @return list<array{module: string, options: array<string, mixed>}> */
    public function all(): array
    {
        $plugins = [];

        foreach ($this->allItems() as $plugin) {
            $plugins[$plugin['module']] = $plugin;
        }

        ksort($plugins);

        return array_values($plugins);
    }

    /** @return list<string> */
    public function modules(): array
    {
        return array_column($this->all(), 'module');
    }

    /**
     * Options compared without regard to key order, so equal options declared differently still match.
     *
     * @param  array<mixed>  $options
     * @return array<mixed>
     */
    private static function normalised(array $options): array
    {
        if (! array_is_list($options)) {
            ksort($options);
        }

        return array_map(static fn (mixed $value): mixed => is_array($value) ? self::normalised($value) : $value, $options);
    }
}
