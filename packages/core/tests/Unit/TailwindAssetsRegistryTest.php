<?php

declare(strict_types=1);

use Capell\Core\Support\Tailwind\TailwindAssetsRegistry;

it('dedupes, trims, and sorts tailwind assets with origins', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry
        ->registerSource('  z/views/**/*.blade.php  ', 'package:z')
        ->registerSource('a/views/**/*.blade.php', 'package:a')
        ->registerSource('a/views/**/*.blade.php', 'package:a-dup')
        ->registerImport('  some-package  ', 'config')
        ->registerImport('another-package', 'provider')
        ->registerPlugin('  @tailwindcss/typography  ', 'config')
        ->registerPlugin('@tailwindcss/form-builder', 'provider');

    expect($registry->sources()->all())->toBe(['a/views/**/*.blade.php', 'z/views/**/*.blade.php'])
        ->and($registry->imports()->all())->toBe(['another-package', 'some-package'])
        ->and($registry->plugins()->all())->toBe(['@tailwindcss/form-builder', '@tailwindcss/typography'])
        ->and($registry->toReport()['sources'])->toBe([
            ['value' => 'a/views/**/*.blade.php', 'origin' => 'package:a'],
            ['value' => 'z/views/**/*.blade.php', 'origin' => 'package:z'],
        ]);
});

it('chains registration methods fluently', function (): void {
    $registry = new TailwindAssetsRegistry;

    $result = $registry
        ->registerSource('path/one', 'origin1')
        ->registerSources(['path/two', 'path/three'], 'origin2')
        ->registerImport('import/one', 'origin3')
        ->registerImports(['import/two'], 'origin4')
        ->registerPlugin('plugin/one', 'origin5')
        ->registerPlugins(['plugin/two'], 'origin6');

    expect($result)->toBeInstanceOf(TailwindAssetsRegistry::class)
        ->and($registry->sources()->count())->toBe(3)
        ->and($registry->imports()->count())->toBe(2)
        ->and($registry->plugins()->count())->toBe(2);
});

it('returns empty collections when no assets registered', function (): void {
    $registry = new TailwindAssetsRegistry;

    expect($registry->sources()->isEmpty())->toBeTrue()
        ->and($registry->imports()->isEmpty())->toBeTrue()
        ->and($registry->plugins()->isEmpty())->toBeTrue();

    expect($registry->toReport())->toBe([
        'imports' => [],
        'plugins' => [],
        'sources' => [],
        'theme_colors' => [],
    ]);
});

it('handles non-string values in batch registration gracefully', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry
        ->registerSources(['valid.css', null, 123, true, 'another.css'], 'test')
        ->registerImports([false, 'import.css', [], 'valid-import'], 'test')
        ->registerPlugins(['plugin-one', new stdClass, 'plugin-two'], 'test');

    expect($registry->sources()->all())->toBe(['another.css', 'valid.css'])
        ->and($registry->imports()->all())->toBe(['import.css', 'valid-import'])
        ->and($registry->plugins()->all())->toBe(['plugin-one', 'plugin-two']);
});

it('preserves first origin when duplicate values registered', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry
        ->registerSource('duplicate.css', 'first-origin')
        ->registerSource('duplicate.css', 'second-origin')
        ->registerSource('duplicate.css', 'third-origin');

    $report = $registry->toReport();

    expect($report['sources'])->toHaveCount(1)
        ->and($report['sources'][0])->toBe(['value' => 'duplicate.css', 'origin' => 'first-origin']);
});

it('registers with null origin when not provided', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry
        ->registerSource('no-origin.css')
        ->registerImport('no-origin-import')
        ->registerPlugin('no-origin-plugin');

    $report = $registry->toReport();

    expect($report['sources'][0])->toBe(['value' => 'no-origin.css', 'origin' => null])
        ->and($report['imports'][0])->toBe(['value' => 'no-origin-import', 'origin' => null])
        ->and($report['plugins'][0])->toBe(['value' => 'no-origin-plugin', 'origin' => null]);
});

it('registers theme colors and sorts them by name', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry
        ->registerThemeColor('secondary', '#6366f1', 'package:alpha')
        ->registerThemeColor('primary', '#3b82f6', 'package:alpha')
        ->registerThemeColor('accent', '#f59e0b', 'package:alpha');

    expect($registry->themeColors()->all())->toBe([
        'accent' => '#f59e0b',
        'primary' => '#3b82f6',
        'secondary' => '#6366f1',
    ])
        ->and($registry->hasThemeColors())->toBeTrue();
});

it('theme color last-registration wins when the same name is registered twice', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry
        ->registerThemeColor('primary', '#3b82f6', 'package:defaults')
        ->registerThemeColor('primary', '#ef4444', 'theme:my-theme');

    expect($registry->themeColors()->get('primary'))->toBe('#ef4444');

    $report = $registry->toReport();
    expect($report['theme_colors']['primary'])->toBe(['value' => '#ef4444', 'origin' => 'theme:my-theme']);
});

it('registers multiple theme colors via registerThemeColors batch', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry->registerThemeColors([
        'primary' => '#3b82f6',
        'secondary' => '#6366f1',
    ], 'provider:blog');

    expect($registry->themeColors()->count())->toBe(2)
        ->and($registry->toReport()['theme_colors'])->toHaveKey('primary')
        ->and($registry->toReport()['theme_colors'])->toHaveKey('secondary');
});

it('ignores theme color entries with empty name or value', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry
        ->registerThemeColor('', '#ff0000')
        ->registerThemeColor('primary', '')
        ->registerThemeColor('  ', '#ff0000')
        ->registerThemeColor('valid', '#00ff00');

    expect($registry->themeColors()->all())->toBe(['valid' => '#00ff00'])
        ->and($registry->hasThemeColors())->toBeTrue();
});

it('hasThemeColors returns false when no theme colors registered', function (): void {
    $registry = new TailwindAssetsRegistry;

    expect($registry->hasThemeColors())->toBeFalse()
        ->and($registry->themeColors()->isEmpty())->toBeTrue();
});
