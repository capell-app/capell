<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Enums\EditorEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\Editor\ContentBuilder;
use Capell\Admin\Filament\Components\Forms\Editor\RichEditor;
use Capell\Admin\Filament\Components\Forms\Editor\TinyEditor;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Enums\ContentStructure;
use Filament\Actions\Action;

class ContentEditor
{
    public static function make(
        ?string $name = 'content',
        ?ContentStructure $structure = null,
        ?EditorEnum $editor = null,
        bool $withContentStructureConversion = false,
        ?callable $configure = null,
    ): ContentBuilder|RichEditor|TinyEditor {
        if ($editor instanceof EditorEnum) {
            $component = self::getEditor($editor, (string) $name);

            return $configure !== null ? $configure($component) : $component;
        }

        if (! $structure instanceof ContentStructure) {
            $structure = ContentStructure::Html;
        }

        if ($structure === ContentStructure::Blocks) {
            $component = self::getContentBuilder((string) $name);
            if ($withContentStructureConversion) {
                self::addConvertContentToHtmlHintAction($component);
            }

            return $configure !== null ? $configure($component) : $component;
        }

        $editor = CapellAdmin::settings()->html_editor;

        $component = self::getEditor($editor, (string) $name);
        if ($withContentStructureConversion) {
            self::addConvertContentToBlocksHintAction($component);
        }

        return $configure !== null ? $configure($component) : $component;
    }

    private static function getEditor(EditorEnum $editor, string $name): RichEditor|TinyEditor
    {
        return match ($editor) {
            EditorEnum::RichEditor => self::getRichEditor($name),
            EditorEnum::TinyMCE => self::getTinyEditor($name),
        };
    }

    private static function getContentBuilder(string $name): ContentBuilder
    {
        return ContentBuilder::make($name);
    }

    private static function getRichEditor(string $name): RichEditor
    {
        return RichEditor::make($name)->dehydrateStateUsing(self::cleanupState(...));
    }

    private static function getTinyEditor(string $name): TinyEditor
    {
        return TinyEditor::make($name)->dehydrateStateUsing(self::cleanupState(...));
    }

    private static function addConvertContentToBlocksHintAction(RichEditor|TinyEditor $component): void
    {
        $component->hintAction(
            Action::make('convertContentToBlocks')
                ->label(__('capell-admin::button.convert_to_content_blocks'))
                ->icon('heroicon-m-squares-2x2')
                ->link()
                ->visible(fn (mixed $livewire): bool => $livewire instanceof EditPage
                    && $livewire->record->content_structure !== ContentStructure::Blocks)
                ->action(function (mixed $livewire): void {
                    if ($livewire instanceof EditPage) {
                        $livewire->pageTypeContentStructureUpdated(ContentStructure::Blocks);
                    }
                }),
        );
    }

    private static function addConvertContentToHtmlHintAction(ContentBuilder $component): void
    {
        $component->hintAction(
            Action::make('convertContentToHtml')
                ->label(__('capell-admin::button.convert_content_to_html'))
                ->icon('heroicon-m-document-text')
                ->link()
                ->visible(fn (mixed $livewire): bool => $livewire instanceof EditPage
                    && $livewire->record->content_structure === ContentStructure::Blocks)
                // Blocks → HTML is destructive: ExtractContentFromBlocksAction
                // concatenates text and drops all block-type metadata. A snapshot
                // is captured automatically (14-day restore window) but the
                // editor must make the choice explicitly.
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalIconColor('danger')
                ->modalHeading(__('capell-admin::button.convert_content_to_html_confirm_heading'))
                ->modalDescription(__('capell-admin::button.convert_content_to_html_confirm_body'))
                ->modalSubmitActionLabel(__('capell-admin::button.convert_content_to_html_confirm_submit'))
                ->color('danger')
                ->action(function (mixed $livewire): void {
                    if ($livewire instanceof EditPage) {
                        $livewire->pageTypeContentStructureUpdated(ContentStructure::Html);
                        $livewire->skipRender();
                    }
                }),
        );
    }

    private static function cleanupState(?string $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        if (strip_tags($state) === '') {
            return null;
        }

        return $state;
    }
}
