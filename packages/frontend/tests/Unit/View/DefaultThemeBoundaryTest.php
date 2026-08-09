<?php

declare(strict_types=1);

it('keeps shared frontend layout free of foundation chrome fallbacks', function (): void {
    $layout = file_get_contents(dirname(__DIR__, 3) . '/resources/views/components/layout/index.blade.php');

    expect($layout)->not->toContain('<x-capell::header.index')
        ->and($layout)->not->toContain("'capell::footer'");
});

it('keeps layout preparation out of the component view', function (): void {
    $layout = file_get_contents(dirname(__DIR__, 3) . '/resources/views/components/layout/index.blade.php');
    $component = file_get_contents(dirname(__DIR__, 3) . '/src/View/Components/Layout.php');

    expect($layout)->not->toContain('<?php')
        ->and($layout)->not->toContain("app('")
        ->and($component)->toContain('declare(strict_types=1)')
        ->and($component)->toContain('final class Layout extends Component');
});

it('keeps the built-in system page layout responsive to dark mode', function (): void {
    $app = file_get_contents(dirname(__DIR__, 3) . '/resources/views/app.blade.php');
    $layout = file_get_contents(dirname(__DIR__, 3) . '/resources/views/components/layout/index.blade.php');
    $styles = file_get_contents(dirname(__DIR__, 3) . '/resources/css/base/default-theme.css');

    expect($app)->toContain('body-class="capell-default-theme"')
        ->and($layout)->toContain('capell-default-theme__layout')
        ->and($layout)->toContain('@blaze-standard-compiler')
        ->and($layout)->toContain('capell-default-theme__brand')
        ->and($layout)->toContain('capell-default-theme__content')
        ->and($styles)->toContain('@media (prefers-color-scheme: dark)')
        ->and($styles)->toContain(':root.dark .capell-default-theme')
        ->and($styles)->toContain(':root.light .capell-default-theme')
        ->and($styles)->toContain('--color-heading: 248 250 252')
        ->and($styles)->toContain('--color-base: 203 213 225')
        ->and($styles)->toContain('color-scheme: dark')
        ->and($styles)->toContain('color-scheme: light');
});

it('declares light and dark desktop and mobile screenshot states', function (): void {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 3) . '/docs/screenshots.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $entries = collect($manifest['entries'])->keyBy('id');

    expect($entries['frontend-published-page']['colorSchemes'] ?? null)->toBe(['light', 'dark'])
        ->and($entries['frontend-published-page']['viewport'] ?? 'desktop')->toBe('desktop')
        ->and($entries['frontend-published-page-mobile']['colorSchemes'] ?? null)->toBe(['light', 'dark'])
        ->and($entries['frontend-published-page-mobile']['viewport'] ?? null)->toBe('mobile');
});

it('exposes the shared main content render hook', function (): void {
    $main = file_get_contents(dirname(__DIR__, 3) . '/resources/views/components/layout/main.blade.php');
    $locations = file_get_contents(dirname(__DIR__, 3) . '/src/Enums/RenderHookLocation.php');

    expect($locations)->toContain("case MainContent = 'mainContent'")
        ->and($main)->toContain('RenderHookLocation::MainContent')
        ->and($main)->toContain('RenderHookLocation::AfterContent')
        ->and($main)->toContain("scenario: 'frontend-main-layout'")
        ->and($main)->toContain("target: 'capell::layout.main'")
        ->and($main)->toContain('{{ $pageSlot }}');
});

it('keeps shared frontend content free of foundation prose and divider tokens', function (): void {
    $content = file_get_contents(dirname(__DIR__, 3) . '/resources/views/components/content.blade.php');

    expect($content)->not->toContain('data-lightbox');
});

it('keeps shared frontend javascript limited to the generic alpine runtime', function (): void {
    $entrypoint = file_get_contents(dirname(__DIR__, 3) . '/resources/js/capell-frontend.js');

    expect($entrypoint)->not->toContain('@ryangjchandler/alpine-tooltip')
        ->and($entrypoint)->toContain('@awcodes/alpine-floating-ui')
        ->and($entrypoint)->not->toContain('utilities/lightbox')
        ->and($entrypoint)->toContain("import Alpine from 'alpinejs'")
        ->and($entrypoint)->toContain('window.Alpine.start()');
});

it('does not retain the legacy vendor build asset bridge', function (): void {
    expect(dirname(__DIR__, 3) . '/src/Support/Assets/VendorBuildAssetContributor.php')->not->toBeFile();
});
