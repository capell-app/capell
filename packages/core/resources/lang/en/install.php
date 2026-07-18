<?php

declare(strict_types=1);

return [
    'cache' => [
        'all' => 'Laravel optimized caches',
        'page' => 'Page cache',
        'config' => 'Config cache',
        'views' => 'Views cache',
        'admin' => 'Capell admin cache',
        'components' => 'Capell components cache',
        'widgets' => 'Capell widgets cache',
        'configurators' => 'Capell configurators cache',
        'filament_components' => 'Filament components cache',
    ],
    'developer_tooling' => [
        'installation_label' => 'Install AI / Agent Bridge developer tooling?',
        'installation_hint' => 'Installs Laravel Boost and Capell Agent Bridge for local agent workflows.',
        'boost_installation_label' => 'Run Laravel Boost installer for Agent Bridge, guidelines, and skills?',
        'boost_installation_hint' => 'Runs boost:install --guidelines --skills --mcp without interaction.',
    ],
    'demo' => [
        'knowledge_site' => 'Capell Knowledge',
        'services_site' => 'Capell Services',
    ],
];
