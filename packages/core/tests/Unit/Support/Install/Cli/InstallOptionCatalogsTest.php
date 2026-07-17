<?php

declare(strict_types=1);

use Capell\Core\Support\Install\Cli\FreshInstallDefaults;
use Capell\Core\Support\Install\Cli\InstallCacheOptionCatalog;
use Capell\Core\Support\Install\Cli\InstallDeveloperToolingChoices;

it('preserves the cache catalogue keys labels commands and defaults', function (): void {
    expect(InstallCacheOptionCatalog::baseOptions())->toBe([
        'all' => 'Laravel optimized caches',
        'page' => 'Page cache',
        'config' => 'Config cache',
        'views' => 'Views cache',
    ])
        ->and(InstallCacheOptionCatalog::optionalOptions())->toBe([
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
        'label' => 'Install AI / Agent Bridge developer tooling?',
        'default' => false,
        'hint' => 'Installs Laravel Boost and Capell Agent Bridge for local agent workflows.',
    ])
        ->and(InstallDeveloperToolingChoices::boostInstallationPrompt())->toBe([
            'label' => 'Run Laravel Boost installer for Agent Bridge, guidelines, and skills?',
            'default' => true,
            'hint' => 'Runs boost:install --guidelines --skills --mcp without interaction.',
        ])
        ->and(InstallDeveloperToolingChoices::explicitlyRequested(false))->toBe([true, true])
        ->and(InstallDeveloperToolingChoices::explicitlyRequested(true))->toBe([true, false])
        ->and(InstallDeveloperToolingChoices::alreadyInstalled())->toBe([true, false])
        ->and(InstallDeveloperToolingChoices::notInstalled())->toBe([false, false]);
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
            'Capell Knowledge',
            'Capell Services',
        ]);
});
