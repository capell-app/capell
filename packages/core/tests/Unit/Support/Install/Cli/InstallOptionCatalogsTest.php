<?php

declare(strict_types=1);

use Capell\Core\Support\Install\Cli\FreshInstallDefaults;
use Capell\Core\Support\Install\Cli\InstallCacheOptionCatalog;
use Capell\Core\Support\Install\Cli\InstallDeveloperToolingChoices;

it('preserves the cache catalogue keys labels commands and defaults', function (): void {
    expect(InstallCacheOptionCatalog::baseOptions())->toBe([
        'all' => __('capell-core::install.cache.all'),
        'page' => __('capell-core::install.cache.page'),
        'config' => __('capell-core::install.cache.config'),
        'views' => __('capell-core::install.cache.views'),
    ])
        ->and(InstallCacheOptionCatalog::optionalOptions())->toBe([
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
        ])
        ->and(InstallCacheOptionCatalog::defaultKeys())->toBe([
            'page',
            'config',
            'views',
            'admin',
            'components',
            'widgets',
            'configurators',
            'filament-components',
        ]);
});

it('preserves developer tooling choices and prompt metadata', function (): void {
    expect(InstallDeveloperToolingChoices::installationPrompt())->toBe([
        'label' => __('capell-core::install.developer_tooling.installation_label'),
        'default' => false,
        'hint' => __('capell-core::install.developer_tooling.installation_hint'),
    ])
        ->and(InstallDeveloperToolingChoices::boostInstallationPrompt())->toBe([
            'label' => __('capell-core::install.developer_tooling.boost_installation_label'),
            'default' => true,
            'hint' => __('capell-core::install.developer_tooling.boost_installation_hint'),
        ])
        ->and(InstallDeveloperToolingChoices::explicitlyRequested(false)->installDeveloperTooling)->toBeTrue()
        ->and(InstallDeveloperToolingChoices::explicitlyRequested(false)->configureBoostDeveloperTooling)->toBeTrue()
        ->and(InstallDeveloperToolingChoices::explicitlyRequested(true)->configureBoostDeveloperTooling)->toBeFalse()
        ->and(InstallDeveloperToolingChoices::alreadyInstalled()->installDeveloperTooling)->toBeTrue()
        ->and(InstallDeveloperToolingChoices::alreadyInstalled()->configureBoostDeveloperTooling)->toBeFalse()
        ->and(InstallDeveloperToolingChoices::notInstalled()->installDeveloperTooling)->toBeFalse()
        ->and(InstallDeveloperToolingChoices::notInstalled()->configureBoostDeveloperTooling)->toBeFalse();
});

it('preserves fresh demo input precedence and default data ordering', function (): void {
    expect(FreshInstallDefaults::hasExplicitDemoInput([
        'url' => null,
        'user' => false,
        'name' => '',
        'email' => null,
        'password' => null,
        'theme' => null,
    ]))->toBeFalse()
        ->and(FreshInstallDefaults::hasExplicitDemoInput(['theme' => 'foundation']))->toBeTrue()
        ->and(FreshInstallDefaults::demoLanguages('en'))->toBe(['en', 'fr', 'de'])
        ->and(FreshInstallDefaults::demoLanguages('cy'))->toBe(['en', 'cy', 'fr', 'de'])
        ->and(FreshInstallDefaults::demoSites('Capell Demo'))->toBe([
            'Capell Demo',
            __('capell-core::install.demo.knowledge_site'),
            __('capell-core::install.demo.services_site'),
        ]);
});

it('keeps extracted installer strings at their English translation keys', function (): void {
    expect([
        'cache.all' => __('capell-core::install.cache.all'),
        'cache.page' => __('capell-core::install.cache.page'),
        'cache.config' => __('capell-core::install.cache.config'),
        'cache.views' => __('capell-core::install.cache.views'),
        'cache.admin' => __('capell-core::install.cache.admin'),
        'cache.components' => __('capell-core::install.cache.components'),
        'cache.widgets' => __('capell-core::install.cache.widgets'),
        'cache.configurators' => __('capell-core::install.cache.configurators'),
        'cache.filament_components' => __('capell-core::install.cache.filament_components'),
        'developer_tooling.installation_label' => __('capell-core::install.developer_tooling.installation_label'),
        'developer_tooling.installation_hint' => __('capell-core::install.developer_tooling.installation_hint'),
        'developer_tooling.boost_installation_label' => __('capell-core::install.developer_tooling.boost_installation_label'),
        'developer_tooling.boost_installation_hint' => __('capell-core::install.developer_tooling.boost_installation_hint'),
        'demo.knowledge_site' => __('capell-core::install.demo.knowledge_site'),
        'demo.services_site' => __('capell-core::install.demo.services_site'),
    ])->toBe([
        'cache.all' => 'Laravel optimized caches',
        'cache.page' => 'Page cache',
        'cache.config' => 'Config cache',
        'cache.views' => 'Views cache',
        'cache.admin' => 'Capell admin cache',
        'cache.components' => 'Capell components cache',
        'cache.widgets' => 'Capell widgets cache',
        'cache.configurators' => 'Capell configurators cache',
        'cache.filament_components' => 'Filament components cache',
        'developer_tooling.installation_label' => 'Install AI / Agent Bridge developer tooling?',
        'developer_tooling.installation_hint' => 'Installs Laravel Boost and Capell Agent Bridge for local agent workflows.',
        'developer_tooling.boost_installation_label' => 'Run Laravel Boost installer for Agent Bridge, guidelines, and skills?',
        'developer_tooling.boost_installation_hint' => 'Runs boost:install --guidelines --skills --mcp without interaction.',
        'demo.knowledge_site' => 'Capell Knowledge',
        'demo.services_site' => 'Capell Services',
    ]);
});
