<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Enums\EditorEnum;
use Capell\Admin\Filament\Components\Forms\ContentEditor as BaseContentEditor;
use Capell\Admin\Filament\Components\Forms\Editor\ContentBuilder;
use Capell\Admin\Filament\Components\Forms\Editor\RichEditor;
use Capell\Admin\Filament\Components\Forms\Editor\TinyEditor;
use Capell\Core\Enums\ContentStructure;

class ContentEditor
{
    public static function make(
        ?string $name = 'content',
        ?ContentStructure $structure = null,
        ?EditorEnum $editor = null,
        ?callable $configure = null,
    ): RichEditor|TinyEditor|ContentBuilder {
        return BaseContentEditor::make(
            name: $name,
            structure: $structure,
            editor: $editor,
            withContentStructureConversion: $name === 'content',
            configure: function (RichEditor|TinyEditor|ContentBuilder $component) use ($configure): RichEditor|TinyEditor|ContentBuilder {
                foreach (app()->tagged('capell-admin:page-content-editor') as $configurator) {
                    if (is_callable($configurator)) {
                        $configurator($component);
                    }
                }

                if ($configure !== null) {
                    return $configure($component);
                }

                return $component;
            },
        );
    }
}
