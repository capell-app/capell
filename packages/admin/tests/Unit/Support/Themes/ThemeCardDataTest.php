<?php

declare(strict_types=1);

use Capell\Admin\Support\Themes\ThemeCardData;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;

it('enriches installed theme cards from registered theme definitions', function (): void {
    $registry = new ThemeRegistry;
    $registry->register(
        definition: new ThemeDefinitionData(
            key: 'knowledge',
            name: 'Knowledge',
            description: 'Resource-led editorial theme.',
            package: 'capell-app/theme-knowledge',
            previewImage: '/themes/knowledge.jpg',
            tags: ['Editorial', 'Resources'],
            bestFit: ['Knowledge base', 'Resource hub'],
            presets: [
                new ThemePresetData(
                    key: 'base',
                    name: 'Base',
                    description: 'Base',
                    previewImage: '/themes/knowledge-base.jpg',
                    values: [],
                ),
            ],
            includedSections: ['navigation', 'hero', 'resource-library', 'footer'],
        ),
    );
    app()->instance(ThemeRegistry::class, $registry);

    $theme = Theme::factory()->make([
        'name' => 'Knowledge',
        'key' => 'knowledge',
        'default' => false,
        'status' => true,
        'admin' => [
            'optionalIntegrations' => ['Blog', 'SEO Suite'],
            'demoReady' => true,
        ],
        'sites_count' => 0,
    ]);

    $card = ThemeCardData::fromTheme($theme);

    expect($card->description)->toBe('Resource-led editorial theme.')
        ->and($card->package)->toBe('capell-app/theme-knowledge')
        ->and($card->tags)->toBe(['Editorial', 'Resources'])
        ->and($card->bestFit)->toBe(['Knowledge base', 'Resource hub'])
        ->and($card->includedSections)->toBe(['navigation', 'hero', 'resource-library', 'footer'])
        ->and($card->optionalIntegrations)->toBe(['Blog', 'SEO Suite'])
        ->and($card->demoReady)->toBeTrue();
});

it('uses the registered theme definition preview image when no admin image exists', function (): void {
    $registry = new ThemeRegistry;
    $registry->register(
        definition: new ThemeDefinitionData(
            key: 'catalogue',
            name: 'Catalogue',
            description: 'Catalogue theme.',
            package: 'capell-app/theme-catalogue',
            previewImage: '/themes/catalogue.jpg',
            tags: [],
            bestFit: [],
            presets: [
                new ThemePresetData(
                    key: 'base',
                    name: 'Base',
                    description: 'Base',
                    previewImage: '/themes/catalogue-base.jpg',
                    values: [],
                ),
            ],
            includedSections: [],
        ),
    );
    app()->instance(ThemeRegistry::class, $registry);

    $theme = Theme::factory()->make([
        'name' => 'Catalogue',
        'key' => 'catalogue',
        'admin' => [],
        'default' => false,
        'status' => true,
    ]);

    expect(ThemeCardData::fromTheme($theme)->imageUrl)
        ->toBe('/themes/catalogue.jpg');
});
