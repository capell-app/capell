<?php

declare(strict_types=1);

return [
    'cache' => [
        'label' => 'Which caches would you like to clear?',
        'hint' => 'Keep the defaults to refresh Capell after installation. Laravel caches also clears application data stored in the default cache store.',
        'all' => 'Laravel caches — includes application data, routes, config and views',
        'page' => 'Page HTML — refresh cached public pages',
        'config' => 'Configuration — reload settings from config files and .env',
        'views' => 'Blade views — recompile templates on the next request',
        'admin' => 'Capell admin — refresh cached admin and theme discovery',
        'components' => 'Capell components — rediscover registered components',
        'widgets' => 'Capell widgets — rediscover admin widgets',
        'configurators' => 'Capell configurators — rediscover admin configurators',
        'filament_components' => 'Filament components — rediscover resources, pages and widgets',
    ],
    'developer_tooling' => [
        'installation_label' => 'Install AI / Agent Bridge developer tooling?',
        'installation_hint' => 'Installs Laravel Boost and Capell Agent Bridge for local agent workflows.',
        'boost_installation_label' => 'Run Laravel Boost installer for Agent Bridge, guidelines, and skills?',
        'boost_installation_hint' => 'Runs boost:install --guidelines --skills --mcp without interaction.',
    ],
    'demo' => [
        'production_credentials_refused' => 'Refusing the known demo administrator credentials in production. Supply a unique administrator password or explicitly pass --allow-demo-credentials for an intentional demo.',
        'knowledge_site' => 'Capell Knowledge',
        'services_site' => 'Capell Services',
    ],
];
