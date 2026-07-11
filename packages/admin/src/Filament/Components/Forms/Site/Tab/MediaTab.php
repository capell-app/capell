<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site\Tab;

use Capell\Admin\Filament\Components\Forms\ImageIconUpload;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;

class MediaTab
{
    public static function make(): Tab
    {
        return Tab::make(__('capell-admin::tab.media'))
            ->icon('heroicon-o-photo')
            ->columns()
            ->statePath('meta')
            ->schema([
                MediaLibraryFileUpload::make('image')
                    ->helperText(__('capell-admin::generic.showcase_image')),

                Fieldset::make(__('capell-admin::form.logo'))
                    ->columns()
                    ->schema([
                        MediaLibraryFileUpload::make('logo')
                            ->label(__('capell-admin::form.logo')),
                        MediaLibraryFileUpload::make('logo_inverted')
                            ->label(__('capell-admin::form.logo_inverted'))
                            ->helperText(__('capell-admin::generic.hint_inverted_image')),
                    ]),

                Grid::make()
                    ->schema([
                        ImageIconUpload::make('icon')
                            ->label(__('capell-admin::form.icon'))
                            ->disk('public')
                            ->directory('site'),
                        ImageIconUpload::make('favicon')
                            ->label(__('capell-admin::form.favicon'))
                            ->disk('public')
                            ->directory('site'),
                    ]),
            ]);
    }
}
