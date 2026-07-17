<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Closure;

use function Laravel\Prompts\multiselect;

final class InstallCacheOptionResolver
{
    /**
     * @param  Closure(string): bool  $hasCommand
     * @return array<int, string>
     */
    public function resolve(bool $clearCache, bool $freshInstall, Closure $hasCommand): array
    {
        if ($clearCache || $freshInstall) {
            return ['all'];
        }

        $options = $this->availableOptions($hasCommand);

        return array_map(static fn (int|string $cache): string => (string) $cache, multiselect(
            label: 'Which caches would you like to clear?',
            options: $options,
            default: $this->defaultKeys($options),
        ));
    }

    /**
     * @param  Closure(string): bool  $hasCommand
     * @return array<string, string>
     */
    public function availableOptions(Closure $hasCommand): array
    {
        $options = InstallCacheOptionCatalog::baseOptions();

        foreach (InstallCacheOptionCatalog::optionalOptions() as $key => $option) {
            if ($hasCommand($option['command'])) {
                $options[$key] = $option['label'];
            }
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string>
     */
    public function defaultKeys(array $options): array
    {
        return array_values(array_filter(
            InstallCacheOptionCatalog::defaultKeys(),
            fn (string $cacheKey): bool => array_key_exists($cacheKey, $options),
        ));
    }
}
