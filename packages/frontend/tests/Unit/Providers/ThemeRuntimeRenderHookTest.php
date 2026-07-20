<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Assets\ThemeTokenRenderer;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\Facades\File;

uses()->group('theme');

it('uses editor active preset when rendering theme token css hook', function (): void {
    Blueprint::factory()->theme()->default()->create();

    $theme = Theme::factory()->createOne([
        'key' => 'hook-theme',
        'meta' => [
            'editor' => [
                'preset' => ['active' => 'launch'],
                'tokens' => ['headingScale' => 'expressive'],
            ],
        ],
    ]);
    $store = new class extends ThemeTokenStore
    {
        public array $calls = [];

        public function put(string $themeKey, string $presetKey, BrandProfileData $brand): string
        {
            $this->calls[] = [
                'themeKey' => $themeKey,
                'presetKey' => $presetKey,
                'headingScale' => $brand->headingScale,
                'customTokens' => $brand->customTokens,
            ];

            $path = storage_path('app/testing/' . $themeKey . '-' . $presetKey . '.css');

            File::ensureDirectoryExists(dirname($path));
            File::put($path, (new ThemeTokenRenderer)->css($brand));

            return $path;
        }

        public function publicUrl(string $path): string
        {
            return '/tokens/' . basename($path);
        }
    };

    $settings = new class implements ThemeRuntimeSettings
    {
        public function activeTheme(): string
        {
            return 'settings-theme';
        }

        public function activePreset(): string
        {
            return 'settings-preset';
        }

        public function brandProfile(): BrandProfileData
        {
            return new BrandProfileData;
        }

        public function themeOverrides(): array
        {
            return [
                'hook-theme' => ['headingScale' => 'settings'],
            ];
        }
    };
    $contextReader = new readonly class($theme) implements FrontendContextReader
    {
        public function __construct(private Theme $theme) {}

        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): ?Pageable
        {
            return null;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): Theme
        {
            return $this->theme;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return $key === null ? [] : null;
        }
    };

    app()->instance(ThemeTokenStore::class, $store);
    app()->instance(ThemeRuntimeSettings::class, $settings);
    app()->instance(FrontendContextReader::class, $contextReader);

    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'hook-theme',
            name: 'Hook Theme',
            description: 'Hook test theme.',
            package: 'capell-app/hook-theme',
            previewImage: '/preview.jpg',
            tags: [],
            bestFit: [],
            presets: [
                new ThemePresetData(
                    key: 'launch',
                    name: 'Launch',
                    description: 'Launch preset.',
                    previewImage: '/preset.jpg',
                    values: ['headingScale' => 'compact'],
                ),
            ],
            frontend: [
                'editor' => [
                    'tokens' => [
                        'safeIdentity' => ['options' => ['balanced']],
                        'x; } body { displayNone' => ['options' => ['unsafe']],
                        'radiusValue' => ['options' => ['999px']],
                    ],
                ],
            ],
        ),
    );

    $registry = resolve(RenderHookRegistry::class);
    $firstHtml = $registry->renderAll(RenderHookLocation::HeadClose);

    $theme->meta = [
        'editor' => [
            'preset' => ['active' => 'launch'],
            'tokens' => [
                'headingScale' => 'compact',
                'ignored' => false,
            ],
        ],
    ];

    $sameRequestHtml = $registry->renderAll(RenderHookLocation::HeadClose);

    app()->forgetScopedInstances();
    app()->instance(ThemeTokenStore::class, $store);
    app()->instance(ThemeRuntimeSettings::class, $settings);
    app()->instance(FrontendContextReader::class, $contextReader);

    $nextRequestHtml = $registry->renderAll(RenderHookLocation::HeadClose);

    expect($store->calls)->toBe([
        [
            'themeKey' => 'hook-theme',
            'presetKey' => 'launch',
            'headingScale' => 'expressive',
            'customTokens' => [],
        ],
        [
            'themeKey' => 'hook-theme',
            'presetKey' => 'launch',
            'headingScale' => 'compact',
            'customTokens' => [],
        ],
        [
            'themeKey' => 'hook-theme',
            'presetKey' => 'launch',
            'headingScale' => 'compact',
            'customTokens' => [],
        ],
    ])
        ->and($firstHtml)->toContain('<style data-capell-theme-tokens>:root {')
        ->and($firstHtml)->toContain('--theme-heading-scale: expressive;')
        ->and($firstHtml)->not->toContain('--theme-x;')
        ->and($firstHtml)->not->toContain('--theme-radius-value: 999px;')
        ->and($sameRequestHtml)->toBe($firstHtml)
        ->and($nextRequestHtml)->toContain('--theme-heading-scale: compact;')
        ->and($nextRequestHtml)->not->toContain('/tokens/hook-theme-launch.css');
});
