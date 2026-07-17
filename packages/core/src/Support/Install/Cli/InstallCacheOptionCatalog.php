<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

final class InstallCacheOptionCatalog
{
    /** @return array<string, string> */
    public static function baseOptions(): array
    {
        return [
            'all' => 'Laravel optimized caches',
            'page' => 'Page cache',
            'config' => 'Config cache',
            'views' => 'Views cache',
        ];
    }

    /**
     * @return array<string, array{label: string, command: string}>
     */
    public static function optionalOptions(): array
    {
        return [
            'admin' => [
                'label' => 'Capell admin cache',
                'command' => 'capell:admin-clear-cache',
            ],
            'components' => [
                'label' => 'Capell components cache',
                'command' => 'capell:clear-components-cache',
            ],
            'widgets' => [
                'label' => 'Capell widgets cache',
                'command' => 'capell:admin-clear-widgets-cache',
            ],
            'configurators' => [
                'label' => 'Capell configurators cache',
                'command' => 'capell:admin-clear-configurators-cache',
            ],
            'filament-components' => [
                'label' => 'Filament components cache',
                'command' => 'filament:clear-cached-components',
            ],
        ];
    }

    /** @return list<string> */
    public static function defaultKeys(): array
    {
        return [
            'page',
            'config',
            'views',
            'admin',
            'components',
            'widgets',
            'configurators',
            'filament-components',
        ];
    }
}
