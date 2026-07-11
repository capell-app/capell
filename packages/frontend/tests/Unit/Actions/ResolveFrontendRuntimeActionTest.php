<?php

declare(strict_types=1);

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Actions\ResolveFrontendRuntimeAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\FrontendRuntimeManifestContributor;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;

it('defaults blade-only pages to the blade runtime when no theme is resolved', function (): void {
    $page = Page::factory()->make(['meta' => null]);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturnNull();
    $context->shouldReceive('layout')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Blade)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::BladeOnly)
        ->and($resolution->runtimeManifest->usesLivewire)->toBeFalse()
        ->and($resolution->runtimeManifest->usesAlpine)->toBeFalse()
        ->and($resolution->runtimeManifest->usesBeacon)->toBeFalse()
        ->and($resolution->runtimeManifest->usesWireNavigate)->toBeFalse();
});

it('defaults theme definitions to the livewire runtime', function (): void {
    $definition = new ThemeDefinitionData(
        key: 'legacy',
        name: 'Legacy',
        description: 'Existing theme without an explicit runtime.',
        package: 'capell-app/theme-legacy',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [],
    );

    expect($definition->runtime)->toBe(FrontendRuntime::Livewire);
});

it('resolves the runtime from the active theme definition', function (): void {
    $page = Page::factory()->make(['meta' => null]);
    $theme = new Theme;
    $theme->key = 'nexus';

    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'nexus',
            name: 'Nexus',
            description: 'Dark SaaS editorial Inertia theme.',
            package: 'capell-app/theme-inertia-nexus',
            previewImage: '/preview.jpg',
            tags: [],
            bestFit: [],
            presets: [],
            runtime: FrontendRuntime::Inertia,
        ),
    );

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturn($theme);
    $context->shouldReceive('layout')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Inertia)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::BladeOnly)
        ->and($resolution->runtimeManifest->usesInertia)->toBeTrue()
        ->and($resolution->runtimeManifest->modules['inertia'])->toBeTrue();
});

it('falls back to blade when a blade-only database theme is not registered', function (): void {
    $page = Page::factory()->make(['meta' => null]);
    $theme = new Theme;
    $theme->key = 'missing-theme';

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturn($theme);
    $context->shouldReceive('layout')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Blade)
        ->and($resolution->runtimeManifest->usesLivewire)->toBeFalse()
        ->and($resolution->runtimeManifest->usesAlpine)->toBeTrue()
        ->and($resolution->runtimeManifest->modules['frontend-chrome'])->toBeTrue();
});

it('uses frontend runtime defaults from registered theme definitions', function (): void {
    $page = Page::factory()->make(['meta' => null]);
    $theme = new Theme;
    $theme->key = 'default';
    $theme->meta = null;

    resolve(ThemeRegistry::class)->register(new ThemeDefinitionData(
        key: 'default',
        name: 'Default',
        description: 'Default theme metadata.',
        package: 'capell-app/frontend',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [new ThemePresetData('default', 'Default', 'Default preset.', '/preview.jpg')],
        runtime: FrontendRuntime::Blade,
        frontend: [
            'runtime' => [
                'uses_alpine' => false,
                'uses_frontend_chrome' => false,
            ],
        ],
    ));

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturn($theme);
    $context->shouldReceive('layout')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Blade)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::BladeOnly)
        ->and($resolution->runtimeManifest->usesAlpine)->toBeFalse()
        ->and($resolution->runtimeManifest->usesLivewire)->toBeFalse()
        ->and($resolution->runtimeManifest->usesIslands)->toBeFalse()
        ->and($resolution->runtimeManifest->usesBeacon)->toBeFalse()
        ->and($resolution->runtimeManifest->modules)->toBe([]);
});

it('lets blade-only database themes opt out of alpine and frontend chrome runtime modules', function (): void {
    $page = Page::factory()->make(['meta' => null]);
    $theme = new Theme;
    $theme->key = 'marketing';
    $theme->meta = [
        'frontend_runtime' => [
            'uses_alpine' => false,
            'uses_frontend_chrome' => false,
        ],
    ];

    app()->singleton('test.marketing-layout-runtime-manifest-contributor', fn (): FrontendRuntimeManifestContributor => new class implements FrontendRuntimeManifestContributor
    {
        public function contribute(FrontendContextReader $context, FrontendRuntimeManifestData $manifest): void
        {
            $manifest->usesAlpine = true;
            $manifest->modules['layout-builder'] = true;
        }
    });
    app()->tag(['test.marketing-layout-runtime-manifest-contributor'], FrontendRuntimeManifestContributor::TAG);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturn($theme);
    $context->shouldReceive('layout')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Blade)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::BladeOnly)
        ->and($resolution->runtimeManifest->usesAlpine)->toBeFalse()
        ->and($resolution->runtimeManifest->usesLivewire)->toBeFalse()
        ->and($resolution->runtimeManifest->usesIslands)->toBeFalse()
        ->and($resolution->runtimeManifest->modules)->toBe(['layout-builder' => true]);
});

it('keeps alpine enabled for opt out themes that still require livewire islands', function (): void {
    $page = Page::factory()->make(['meta' => null]);
    $theme = new Theme;
    $theme->key = 'marketing';
    $theme->meta = [
        'frontend_runtime' => [
            'uses_alpine' => false,
            'uses_frontend_chrome' => false,
        ],
    ];

    app()->singleton('test.marketing-livewire-runtime-manifest-contributor', fn (): FrontendRuntimeManifestContributor => new class implements FrontendRuntimeManifestContributor
    {
        public function contribute(FrontendContextReader $context, FrontendRuntimeManifestData $manifest): void
        {
            $manifest->usesAlpine = true;
            $manifest->usesLivewire = true;
            $manifest->usesIslands = true;
            $manifest->modules['layout-builder'] = true;
        }
    });
    app()->tag(['test.marketing-livewire-runtime-manifest-contributor'], FrontendRuntimeManifestContributor::TAG);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturn($theme);
    $context->shouldReceive('layout')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtimeManifest->usesAlpine)->toBeTrue()
        ->and($resolution->runtimeManifest->usesLivewire)->toBeTrue()
        ->and($resolution->runtimeManifest->usesIslands)->toBeTrue()
        ->and($resolution->runtimeManifest->modules)->toBe(['layout-builder' => true]);
});

it('routes full livewire pages to the livewire runtime', function (): void {
    $page = Page::factory()->make([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::FullLivewire->value],
    ]);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Livewire)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::FullLivewire)
        ->and($resolution->runtimeManifest->usesLivewire)->toBeTrue()
        ->and($resolution->runtimeManifest->usesAlpine)->toBeTrue()
        ->and($resolution->runtimeManifest->usesWireNavigate)->toBeTrue();
});

it('routes blade island pages to blade with a livewire island runtime manifest', function (): void {
    $page = Page::factory()->make([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeWithIslands->value],
    ]);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Blade)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::BladeWithIslands)
        ->and($resolution->runtimeManifest->usesLivewire)->toBeTrue()
        ->and($resolution->runtimeManifest->usesIslands)->toBeTrue()
        ->and($resolution->runtimeManifest->usesWireNavigate)->toBeFalse();
});

it('allows tagged contributors to enrich blade runtime manifests', function (): void {
    $page = Page::factory()->make(['meta' => null]);
    $layout = Layout::factory()->make();

    app()->singleton('test.frontend-runtime-manifest-contributor', fn (): FrontendRuntimeManifestContributor => new class implements FrontendRuntimeManifestContributor
    {
        public function contribute(FrontendContextReader $context, FrontendRuntimeManifestData $manifest): void
        {
            if (! $context->layout() instanceof Layout) {
                return;
            }

            $manifest->usesAlpine = true;
            $manifest->usesLivewire = true;
            $manifest->usesIslands = true;
            $manifest->modules['test-module'] = true;
        }
    });
    app()->tag(['test.frontend-runtime-manifest-contributor'], FrontendRuntimeManifestContributor::TAG);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('layout')->andReturn($layout);
    $context->shouldReceive('theme')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Blade)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::BladeOnly)
        ->and($resolution->runtimeManifest->usesAlpine)->toBeTrue()
        ->and($resolution->runtimeManifest->usesLivewire)->toBeTrue()
        ->and($resolution->runtimeManifest->usesIslands)->toBeTrue()
        ->and($resolution->runtimeManifest->modules['test-module'])->toBeTrue();
});

it('keeps blade manifests safe when no runtime manifest contributors are registered', function (): void {
    $page = Page::factory()->make(['meta' => null]);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Blade)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::BladeOnly)
        ->and($resolution->runtimeManifest->usesAlpine)->toBeFalse()
        ->and($resolution->runtimeManifest->usesLivewire)->toBeFalse()
        ->and($resolution->runtimeManifest->usesIslands)->toBeFalse()
        ->and($resolution->runtimeManifest->usesInertia)->toBeFalse()
        ->and($resolution->runtimeManifest->modules)->toBe([]);
});

it('ignores malformed tagged runtime manifest contributors', function (): void {
    $page = Page::factory()->make(['meta' => null]);

    app()->singleton('test.invalid-runtime-manifest-contributor', fn (): object => new stdClass);
    app()->tag(['test.invalid-runtime-manifest-contributor'], FrontendRuntimeManifestContributor::TAG);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturnNull();

    $resolution = ResolveFrontendRuntimeAction::run($context);

    expect($resolution->runtime)->toBe(FrontendRuntime::Blade)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe(RenderingStrategyEnum::BladeOnly)
        ->and($resolution->runtimeManifest->usesAlpine)->toBeFalse()
        ->and($resolution->runtimeManifest->usesLivewire)->toBeFalse()
        ->and($resolution->runtimeManifest->usesIslands)->toBeFalse()
        ->and($resolution->runtimeManifest->usesInertia)->toBeFalse()
        ->and($resolution->runtimeManifest->modules)->toBe([]);
});
