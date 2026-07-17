<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

final class InstallCacheOptionCatalog
{
    /** @return array<string, string> */
    public static function baseOptions(): array
    {
        return [
            'all' => __('capell-core::install.cache.all'),
            'page' => __('capell-core::install.cache.page'),
            'config' => __('capell-core::install.cache.config'),
            'views' => __('capell-core::install.cache.views'),
        ];
    }

    /**
     * @return array<string, array{label: string, command: string}>
     */
    public static function optionalOptions(): array
    {
        return [
            'admin' => [
                'label' => __('capell-core::install.cache.admin'),
                'command' => 'capell:admin-clear-cache',
            ],
            'components' => [
                'label' => __('capell-core::install.cache.components'),
                'command' => 'capell:clear-components-cache',
            ],
            'widgets' => [
                'label' => __('capell-core::install.cache.widgets'),
                'command' => 'capell:admin-clear-widgets-cache',
            ],
            'configurators' => [
                'label' => __('capell-core::install.cache.configurators'),
                'command' => 'capell:admin-clear-configurators-cache',
            ],
            'filament-components' => [
                'label' => __('capell-core::install.cache.filament_components'),
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
