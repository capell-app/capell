<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Rendering\ViewSectionRenderer;
use Capell\Core\ThemeStudio\Theme\ThemePackageRegistrar;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;

it('registers Blade theme definitions and section views', function (): void {
    $registry = new ThemeRegistry;
    $registrar = new ThemePackageRegistrar($registry);

    $registrar->registerBladeTheme(
        definition: new ThemeDefinitionData(
            key: 'equidynamics',
            name: 'Equidynamics',
            description: 'Equidynamics',
            package: 'app/equidynamics-theme',
            previewImage: '/preview.jpg',
            tags: [],
            bestFit: [],
            presets: [],
            includedSections: ['hero'],
            extends: 'default',
        ),
        layoutView: 'equidynamics::page',
        sectionViews: ['hero' => 'equidynamics::sections.hero'],
    );

    expect($registry->has('equidynamics'))->toBeTrue()
        ->and($registry->definition('equidynamics')->extends)->toBe('default')
        ->and($registry->sectionRenderer('equidynamics', 'hero'))->toBeInstanceOf(ViewSectionRenderer::class);
});
