<?php

declare(strict_types=1);

it('keeps shared frontend layout free of foundation chrome fallbacks', function (): void {
    $layout = file_get_contents(dirname(__DIR__, 3) . '/resources/views/components/layout/index.blade.php');

    expect($layout)->not->toContain('<x-capell::header.index')
        ->and($layout)->not->toContain("'capell::footer'");
});

it('exposes the shared main content render hook', function (): void {
    $main = file_get_contents(dirname(__DIR__, 3) . '/resources/views/components/layout/main.blade.php');
    $locations = file_get_contents(dirname(__DIR__, 3) . '/src/Enums/RenderHookLocation.php');

    expect($locations)->toContain("case MainContent = 'mainContent'")
        ->and($main)->toContain('RenderHookLocation::MainContent')
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
