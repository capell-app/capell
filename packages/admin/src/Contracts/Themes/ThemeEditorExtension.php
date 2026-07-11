<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Themes;

use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Capell\Admin\Data\Themes\ThemeEditorStateData;

interface ThemeEditorExtension
{
    public const string TAG = 'capell.admin.theme-editor-extension';

    public function supports(ThemeEditorContextData $context): bool;

    /**
     * @return array<int, mixed>
     */
    public function editorSections(ThemeEditorContextData $context): array;

    /**
     * @return array<string, mixed>
     */
    public function samplePreviewContent(ThemeEditorContextData $context): array;

    public function previewComponent(ThemeEditorContextData $context): ?string;

    /**
     * @return array<string, string>
     */
    public function cssVariables(ThemeEditorStateData $state, ThemeEditorContextData $context): array;

    /**
     * @return array<string, string>
     */
    public function dataAttributes(ThemeEditorStateData $state, ThemeEditorContextData $context): array;
}
