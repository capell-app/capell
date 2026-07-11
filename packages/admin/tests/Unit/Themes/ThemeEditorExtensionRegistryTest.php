<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Themes\ThemeEditorExtension;
use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Capell\Admin\Data\Themes\ThemeEditorStateData;
use Capell\Admin\Support\Themes\ThemeEditorExtensionRegistry;
use Capell\Core\Models\Theme;

uses()->group('theme');

it('returns only extensions that support the current theme editor context', function (): void {
    $theme = Theme::factory()->createOne(['key' => 'supported']);
    $context = ThemeEditorContextData::forTheme($theme);

    app()->bind('test.supported-theme-editor-extension', fn (): ThemeEditorExtension => new class implements ThemeEditorExtension
    {
        public function supports(ThemeEditorContextData $context): bool
        {
            return $context->themeKey === 'supported';
        }

        public function editorSections(ThemeEditorContextData $context): array
        {
            return ['section'];
        }

        public function samplePreviewContent(ThemeEditorContextData $context): array
        {
            return ['headline' => 'Sample'];
        }

        public function previewComponent(ThemeEditorContextData $context): ?string
        {
            return null;
        }

        public function cssVariables(ThemeEditorStateData $state, ThemeEditorContextData $context): array
        {
            return ['--package-token' => 'value'];
        }

        public function dataAttributes(ThemeEditorStateData $state, ThemeEditorContextData $context): array
        {
            return ['data-package' => $context->themeKey];
        }
    });

    app()->tag(['test.supported-theme-editor-extension'], ThemeEditorExtension::TAG);

    $extensions = resolve(ThemeEditorExtensionRegistry::class)->forContext($context);

    expect($extensions)->toHaveCount(1)
        ->and($extensions->first()?->samplePreviewContent($context))->toBe(['headline' => 'Sample']);
});
