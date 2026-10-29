<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\BuildThemeEditorPreviewAction;
use Capell\Admin\Contracts\Themes\ThemeEditorExtension;
use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Capell\Admin\Data\Themes\ThemeEditorStateData;
use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\View;

uses()->group('theme');

it('builds preview html from unsaved editor state', function (): void {
    $state = ThemeEditorStateData::defaults();
    $state->brand['primaryColor'] = '#2563eb';
    $state->surface['surfaceColor'] = '#f8fafc';
    $state->advanced['customCss'] = '.theme-preview__hero{letter-spacing:0;}';
    $state->admin['preview']['colorMode'] = 'dark';
    $state->header['enabled'] = false;
    $state->footer['copy'] = 'Preview footer copy';

    $preview = BuildThemeEditorPreviewAction::run($state);

    expect($preview->cssVariables['--theme-primary'])->toBe('#2563eb')
        ->and($preview->cssVariables['--theme-surface'])->toBe('#f8fafc')
        ->and($preview->cssVariables['--theme-primary-contrast'])->toBe('#ffffff')
        ->and($preview->dataAttributes['data-color-mode'])->toBe('dark')
        ->and($preview->html)->toContain('--theme-primary:#2563eb;')
        ->and($preview->html)->toContain('.theme-preview__hero{letter-spacing:0;}')
        ->and($preview->html)->not->toContain('<header class="theme-preview__header"')
        ->and($preview->html)->toContain('Preview footer copy')
        ->and($preview->html)->toContain('tabindex="-1"');
});

it('applies extension preview content and token mappings', function (): void {
    $theme = Theme::factory()->createOne(['key' => 'package-theme']);
    $context = ThemeEditorContextData::forTheme($theme);

    app()->bind('test.package-theme-editor-preview-extension', fn (): ThemeEditorExtension => new class implements ThemeEditorExtension
    {
        public function supports(ThemeEditorContextData $context): bool
        {
            return $context->themeKey === 'package-theme';
        }

        public function editorSections(ThemeEditorContextData $context): array
        {
            return [];
        }

        public function samplePreviewContent(ThemeEditorContextData $context): array
        {
            return [
                'headline' => 'Package sample headline',
                'body' => 'Package sample body.',
            ];
        }

        public function previewComponent(ThemeEditorContextData $context): ?string
        {
            return null;
        }

        public function cssVariables(ThemeEditorStateData $state, ThemeEditorContextData $context): array
        {
            return [
                '--package-accent' => '#f59e0b',
                '--theme-accent' => '#ffffff',
                'bad-token" onclick="' => 'ignored',
            ];
        }

        public function dataAttributes(ThemeEditorStateData $state, ThemeEditorContextData $context): array
        {
            return [
                'data-package-theme' => $context->themeKey,
                'bad-attribute"' => 'ignored',
            ];
        }
    });
    app()->tag(['test.package-theme-editor-preview-extension'], ThemeEditorExtension::TAG);

    $preview = BuildThemeEditorPreviewAction::run(ThemeEditorStateData::defaults(), $context);

    expect($preview->cssVariables['--package-accent'])->toBe('#f59e0b')
        ->and($preview->cssVariables['--theme-accent-contrast'])->toBe('#000000')
        ->and($preview->dataAttributes['data-package-theme'])->toBe('package-theme')
        ->and($preview->cssVariables)->not->toHaveKey('bad-token" onclick="')
        ->and($preview->dataAttributes)->not->toHaveKey('bad-attribute"')
        ->and($preview->html)->toContain('Package sample headline')
        ->and($preview->html)->toContain('data-package-theme="package-theme"');
});

it('contains closing style tags from custom css inside the sandbox preview', function (): void {
    $state = ThemeEditorStateData::defaults();
    $state->advanced['customCss'] = '</style><script>alert("x")</script>';

    $preview = BuildThemeEditorPreviewAction::run($state);

    expect($preview->html)->toContain('<\\/style><script>alert("x")</script>')
        ->and($preview->html)->not->toContain('</style><script>alert("x")</script>');
});

it('uses an extension preview component when one is provided', function (): void {
    View::addLocation(__DIR__ . '/../../../Fixtures/views');

    $theme = Theme::factory()->createOne(['key' => 'component-preview']);
    $context = ThemeEditorContextData::forTheme($theme);

    app()->bind('test.package-theme-editor-component-extension', fn (): ThemeEditorExtension => new class implements ThemeEditorExtension
    {
        public function supports(ThemeEditorContextData $context): bool
        {
            return $context->themeKey === 'component-preview';
        }

        public function editorSections(ThemeEditorContextData $context): array
        {
            return [];
        }

        public function samplePreviewContent(ThemeEditorContextData $context): array
        {
            return ['headline' => 'Component-provided preview'];
        }

        public function previewComponent(ThemeEditorContextData $context): string
        {
            return 'theme-editor-preview-component';
        }

        public function cssVariables(ThemeEditorStateData $state, ThemeEditorContextData $context): array
        {
            return [];
        }

        public function dataAttributes(ThemeEditorStateData $state, ThemeEditorContextData $context): array
        {
            return [];
        }
    });
    app()->tag(['test.package-theme-editor-component-extension'], ThemeEditorExtension::TAG);

    $preview = BuildThemeEditorPreviewAction::run(ThemeEditorStateData::defaults(), $context);

    expect($preview->html)->toContain('data-component-preview="component-preview"')
        ->and($preview->html)->toContain('Component-provided preview');
});

it('draws the fallback primary button with the computed contrast colour', function (string $primary, string $expectedContrast): void {
    $relativeLuminance = function (string $hex): float {
        $channels = array_map(
            static function (float|int $value): float {
                $channel = $value / 255;

                return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
            },
            array_map(hexdec(...), str_split(ltrim($hex, '#'), 2)),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };

    foreach (['light', 'dark'] as $mode) {
        $state = ThemeEditorStateData::defaults();
        $state->brand['primaryColor'] = $primary;
        $state->admin['preview']['colorMode'] = $mode;

        $preview = BuildThemeEditorPreviewAction::run($state);
        $contrast = $preview->cssVariables['--theme-primary-contrast'];
        $lighter = max($relativeLuminance($primary), $relativeLuminance($contrast));
        $darker = min($relativeLuminance($primary), $relativeLuminance($contrast));

        expect($contrast)->toBe($expectedContrast)
            ->and(($lighter + 0.05) / ($darker + 0.05))->toBeGreaterThanOrEqual(4.5)
            ->and($preview->html)->toContain('background:var(--theme-primary);color:var(--theme-primary-contrast);')
            ->and($preview->html)->not->toContain('background:var(--theme-primary);color:white;');
    }
})->with([
    'deep blue' => ['#1d4ed8', '#ffffff'],
    'bright yellow' => ['#facc15', '#000000'],
    'teal' => ['#14b8a6', '#000000'],
    'dark green' => ['#166534', '#ffffff'],
]);
