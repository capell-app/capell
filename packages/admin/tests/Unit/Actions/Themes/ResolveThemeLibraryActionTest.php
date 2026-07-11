<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\ResolveThemeLibraryAction;
use Capell\Admin\Contracts\Themes\PendingThemeInstallProvider;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Rendering\BladeThemeRenderer;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;

beforeEach(function (): void {
    Blueprint::factory()->theme()->default()->create();
});

it('resolves installed, available, pending, and warning theme library sections', function (): void {
    $registry = new ThemeRegistry;
    $installedDefinition = themeLibraryTestDefinition('installed-theme', 'Installed Theme');
    $availableDefinition = themeLibraryTestDefinition('available-theme', 'Available Theme', previewImage: '');

    $registry->register(
        definition: $installedDefinition,
        themeRenderer: new BladeThemeRenderer('installed-theme', 'missing-layout', []),
        sectionRenderers: [],
    );
    $registry->register(
        definition: $availableDefinition,
        themeRenderer: new BladeThemeRenderer('available-theme', 'missing-layout', []),
        sectionRenderers: [],
    );
    app()->instance(ThemeRegistry::class, $registry);

    $theme = Theme::factory()->createOne([
        'name' => 'Installed database theme',
        'key' => 'installed-theme',
    ]);
    Site::factory()->theme($theme)->create();

    $library = ResolveThemeLibraryAction::run();

    expect($library['installed'])->toHaveCount(1)
        ->and($library['installed'][0]->themeKey)->toBe('installed-theme')
        ->and($library['installed'][0]->siteCount)->toBe(1)
        ->and($library['available'])->toHaveCount(1)
        ->and($library['available'][0]->themeKey)->toBe('available-theme')
        ->and($library['pending'])->toBe(0)
        ->and(collect($library['warnings'])->pluck('themeKey')->all())->toContain('available-theme');
});

it('resolves pending theme installs from tagged providers', function (): void {
    app()->bind('test.pending-theme-install-provider', fn (): PendingThemeInstallProvider => new class implements PendingThemeInstallProvider
    {
        public function pendingThemeInstalls(): array
        {
            return [
                [
                    'name' => 'Agency Theme',
                    'package' => 'capell-app/theme-agency',
                    'command' => 'composer require capell-app/theme-agency',
                ],
            ];
        }
    });
    app()->tag(['test.pending-theme-install-provider'], PendingThemeInstallProvider::TAG);

    $library = ResolveThemeLibraryAction::run();

    expect($library['pending'])->toBe(1)
        ->and($library['pendingInstalls'])->toBe([
            [
                'name' => 'Agency Theme',
                'package' => 'capell-app/theme-agency',
                'command' => 'composer require capell-app/theme-agency',
            ],
        ]);
});

it('ignores unused legacy foundation theme records when the default foundation definition is registered', function (): void {
    $registry = new ThemeRegistry;
    $registry->register(
        definition: themeLibraryTestDefinition('default', 'Foundation', package: 'capell-app/foundation-theme'),
        themeRenderer: new BladeThemeRenderer('default', 'missing-layout', []),
        sectionRenderers: [],
    );
    app()->instance(ThemeRegistry::class, $registry);

    Theme::factory()->createOne([
        'name' => 'Foundation',
        'key' => 'foundation',
    ]);

    $library = ResolveThemeLibraryAction::run();

    expect($library['installed'])->toBeEmpty()
        ->and($library['available'])->toHaveCount(1)
        ->and($library['available'][0]->themeKey)->toBe('default');
});

function themeLibraryTestDefinition(string $key, string $name, string $previewImage = '/themes/test.jpg', ?string $package = null): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: $key,
        name: $name,
        description: $name . ' description.',
        package: $package ?? 'capell-app/' . $key,
        previewImage: $previewImage,
        tags: ['portfolio'],
        bestFit: ['marketing'],
        presets: [
            new ThemePresetData(
                key: 'default',
                name: 'Default',
                description: 'Default preset.',
                previewImage: $previewImage,
                values: [],
            ),
        ],
        includedSections: ['navigation', 'hero', 'features', 'footer'],
        assets: ['frontend' => '/themes/test.css'],
    );
}
