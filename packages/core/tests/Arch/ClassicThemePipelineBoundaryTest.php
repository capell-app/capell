<?php

declare(strict_types=1);
use Capell\Core\ThemeStudio\Actions\RenderCurrentThemePageAction;
use Capell\Core\ThemeStudio\Actions\RenderThemePageAction;
use Capell\Core\ThemeStudio\Contracts\SectionRenderer;
use Capell\Core\ThemeStudio\Contracts\ThemePageAdapter;
use Capell\Core\ThemeStudio\Contracts\ThemeRenderer;
use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Core\ThemeStudio\Rendering\BladeThemeRenderer;
use Capell\Core\ThemeStudio\Rendering\ViewSectionRenderer;
use Capell\Core\ThemeStudio\Theme\ThemePackageRegistrar;
use Capell\Core\ThemeStudio\Theme\ThemePageAdapterRegistry;

it('does not restore the classic theme section rendering pipeline', function (string $class): void {
    expect(class_exists($class) || interface_exists($class))->toBeFalse();
})->with([
    RenderCurrentThemePageAction::class,
    RenderThemePageAction::class,
    SectionRenderer::class,
    ThemePageAdapter::class,
    ThemeRenderer::class,
    ThemeSection::class,
    ThemePageData::class,
    BladeThemeRenderer::class,
    ViewSectionRenderer::class,
    ThemePageAdapterRegistry::class,
    ThemePackageRegistrar::class,
]);
