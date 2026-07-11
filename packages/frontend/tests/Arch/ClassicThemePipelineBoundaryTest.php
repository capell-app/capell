<?php

declare(strict_types=1);
use Capell\Frontend\Contracts\ThemeSectionPayloadContributor;
use Capell\Frontend\Support\Themes\DefaultTheme;
use Capell\Frontend\ThemeStudio\Adapters\CapellFrontendThemePageAdapter;

it('does not restore frontend classic theme adapters', function (string $class): void {
    expect(class_exists($class) || interface_exists($class))->toBeFalse();
})->with([
    ThemeSectionPayloadContributor::class,
    DefaultTheme::class,
    CapellFrontendThemePageAdapter::class,
]);
