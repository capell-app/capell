<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets;

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Capell\Admin\Filament\Components\Forms\Editor\RichEditor;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class CardsFilamentWidget implements FilamentWidget
{
    public static function getWidgetName(): string
    {
        return 'cards';
    }

    public static function make(): Block
    {
        return Block::make('cards')
            ->label(__('capell-admin::widget.cards'))
            ->icon('heroicon-o-identification')
            ->schema([
                Repeater::make('items')
                    ->defaultItems(1)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('title'),

                        RichEditor::make('content'),

                        self::imageUpload(),

                        Select::make('alignment')
                            ->options([
                                'left' => __('capell-admin::generic.left'),
                                'right' => __('capell-admin::generic.right'),
                                'center' => __('capell-admin::generic.center'),
                            ]),
                    ]),
            ]);
    }

    private static function imageUpload(): Field
    {
        $field = MediaLibraryFileUpload::make('image');

        if (method_exists($field, 'preserveFilenames')) {
            $field->preserveFilenames();
        }

        return $field;
    }
}
