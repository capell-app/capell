<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Settings\FrontendSettings;
use Capell\Frontend\Settings\FrontendSettingsReader;

it('reads settings and applies defaults', function (): void {
    config()->set('capell-frontend.default_layout', 'default');
    config()->set('capell-frontend.foundation_theme', 'default');

    $reader = resolve(FrontendSettingsReader::class);

    expect($reader->defaultLayoutKey())->toBe('default')
        ->and($reader->defaultThemeKey())->toBe('default');
});

it('reads html minification from frontend settings', function (): void {
    $settings = resolve(FrontendSettings::class);
    $settings->minify_html = false;
    $settings->save();

    expect(resolve(FrontendSettingsReader::class)->minifyHtml())->toBeFalse();
});

it('reads fresh scoped settings from a long lived reader', function (): void {
    $reader = resolve(FrontendSettingsReaderInterface::class);

    expect($reader->minifyHtml())->toBeTrue();

    app()->forgetScopedInstances();

    $settings = resolve(FrontendSettings::class);
    $settings->minify_html = false;
    $settings->save();

    app()->forgetScopedInstances();

    expect($reader->minifyHtml())->toBeFalse();
});

it('reuses frontend settings inside the current scope and refreshes after scope reset', function (): void {
    $reader = resolve(FrontendSettingsReaderInterface::class);

    $firstSettings = $reader->settings();
    $secondSettings = $reader->settings();

    app()->forgetScopedInstances();

    expect($secondSettings)
        ->toBe($firstSettings)
        ->and($reader->settings())
        ->not->toBe($firstSettings);
});
