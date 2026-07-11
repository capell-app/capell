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
