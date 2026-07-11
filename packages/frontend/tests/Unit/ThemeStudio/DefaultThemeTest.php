<?php

declare(strict_types=1);

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\FooterData;
use Capell\Core\ThemeStudio\Data\HeroSectionData;
use Capell\Core\ThemeStudio\Data\NavigationData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Core\ThemeStudio\Rendering\BladeThemeRenderer;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Support\Themes\DefaultTheme;
use Symfony\Component\HttpFoundation\Response;

it('registers the built in default blade theme from frontend', function (): void {
    $registry = resolve(ThemeRegistry::class);

    expect($registry->has(DefaultTheme::KEY))->toBeTrue();

    $definition = $registry->definition(DefaultTheme::KEY);

    expect($definition->key)->toBe('default')
        ->and($definition->name)->toBe('Default')
        ->and($definition->package)->toBe('capell-app/frontend')
        ->and($definition->previewImage)->toBe('')
        ->and($definition->assets)->toBe(['css' => 'vendor/capell-frontend/capell-frontend.css'])
        ->and($definition->runtime)->toBe(FrontendRuntime::Blade)
        ->and(data_get($definition->frontend, 'runtime.uses_alpine'))->toBeFalse()
        ->and(data_get($definition->frontend, 'runtime.uses_frontend_chrome'))->toBeFalse();
});

it('renders safe static html for the built in default theme', function (): void {
    $registry = resolve(ThemeRegistry::class);
    $renderer = $registry->renderer(DefaultTheme::KEY);

    $html = $renderer->render(new ThemePageData(
        title: 'Launch page',
        brand: new BrandProfileData,
        sections: [
            HeroSectionData::from([
                'heading' => 'Launch a simple site',
                'summary' => 'A plain static default page.',
                'actions' => [
                    ['label' => 'Read more', 'url' => '/about'],
                ],
            ]),
        ],
        navigation: NavigationData::from([
            'brandName' => 'Example Site',
            'items' => [
                ['label' => 'Home', 'url' => '/'],
                ['label' => 'About', 'url' => '/about'],
            ],
        ]),
        footer: FooterData::from([
            'brandName' => 'Example Site',
            'summary' => 'Simple public footer.',
            'columns' => [
                ['heading' => 'Explore', 'links' => [['label' => 'Home', 'url' => '/']]],
            ],
        ]),
    ));

    AssertPublicHtmlContainsNoAuthoringSurfaceAction::run(new Response($html, Response::HTTP_OK, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]));

    expect($html)->toContain('default-theme-shell')
        ->and($html)->toContain('Launch a simple site')
        ->and($html)->not->toContain('x-data')
        ->and($html)->not->toContain('wire:')
        ->and($html)->not->toContain('data-carousel')
        ->and($html)->not->toContain('data-lightbox')
        ->and($html)->not->toContain('capell-foundation-theme');
});

it('does not render raw brand tokens or empty unsafe links in the built in default theme', function (): void {
    $registry = resolve(ThemeRegistry::class);
    $renderer = $registry->renderer(DefaultTheme::KEY);

    $html = $renderer->render(new ThemePageData(
        title: 'Unsafe input page',
        brand: new BrandProfileData(primaryColor: 'url(javascript:alert(1))'),
        sections: [
            HeroSectionData::from([
                'heading' => 'Sanitized hero',
                'actions' => [
                    ['label' => 'Unsafe', 'url' => null],
                    ['label' => 'Safe', 'url' => '/safe'],
                ],
            ]),
        ],
        navigation: NavigationData::from([
            'brandName' => 'Example Site',
            'items' => [
                ['label' => 'Unsafe', 'url' => null],
                ['label' => 'Safe', 'url' => '/safe'],
            ],
            'ctaLabel' => 'Empty CTA',
            'ctaUrl' => '',
        ]),
        footer: FooterData::from([
            'brandName' => 'Example Site',
            'columns' => [
                ['heading' => 'Explore', 'links' => [
                    ['label' => 'Unsafe', 'url' => null],
                    ['label' => 'Safe', 'url' => '/safe'],
                ]],
            ],
        ]),
    ));

    expect($html)->not->toContain('url(javascript:alert(1))')
        ->and($html)->not->toContain('href=""')
        ->and($html)->not->toContain('>Unsafe<')
        ->and($html)->toContain('href="/safe"');
});

it('lets child themes inherit default section renderers without foundation', function (): void {
    $registry = resolve(ThemeRegistry::class);

    $registry->register(
        definition: new ThemeDefinitionData(
            key: 'child',
            name: 'Child',
            description: 'Child theme with default fallbacks.',
            package: 'capell-app/theme-child',
            previewImage: '/preview.jpg',
            tags: [],
            bestFit: [],
            presets: [],
            includedSections: ['navigation', 'hero', 'footer'],
            runtime: FrontendRuntime::Blade,
            extends: DefaultTheme::KEY,
        ),
        themeRenderer: new BladeThemeRenderer(
            themeKey: 'child',
            layoutView: 'missing-child-layout',
            sectionRenderers: [],
        ),
        sectionRenderers: [],
    );

    expect($registry->sectionRenderer('child', 'navigation')?->themeKey())->toBe(DefaultTheme::KEY)
        ->and($registry->sectionRenderer('child', 'footer')?->themeKey())->toBe(DefaultTheme::KEY);
});
