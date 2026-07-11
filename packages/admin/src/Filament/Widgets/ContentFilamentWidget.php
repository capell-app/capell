<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets;

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\ContentEditor;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Core\Enums\MediaAlignment;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;

class ContentFilamentWidget implements FilamentWidget
{
    public static function getWidgetName(): string
    {
        return 'content';
    }

    public static function make(): Block
    {
        return Block::make('content')
            ->label(__('capell-admin::widget.content'))
            ->icon('heroicon-m-bars-3-bottom-left')
            ->schema([
                ContentEditor::make(editor: CapellAdmin::settings()->html_editor)
                    ->hiddenLabel(),

                self::mediaUpload(),

                Select::make('mediaAlign')
                    ->label(__('capell-admin::widget.content_media_align'))
                    ->options(MediaAlignment::class)
                    ->native(false)
                    ->nullable(),

                Select::make('mediaOrdering')
                    ->label(__('capell-admin::widget.content_media_ordering'))
                    ->options([
                        'before' => __('capell-admin::widget.content_media_before'),
                        'after' => __('capell-admin::widget.content_media_after'),
                    ])
                    ->default('before')
                    ->native(false),
            ]);
    }

    private static function mediaUpload(): Field
    {
        $field = MediaLibraryFileUpload::make('media')
            ->label(__('capell-admin::widget.content_media'));

        if (method_exists($field, 'preserveFilenames')) {
            $field->preserveFilenames();
        }

        return $field;
    }
}
