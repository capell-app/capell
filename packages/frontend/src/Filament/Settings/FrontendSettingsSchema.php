<?php

declare(strict_types=1);

namespace Capell\Frontend\Filament\Settings;

use Capell\Core\Contracts\SettingsSchema;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FrontendSettingsSchema implements SettingsSchema
{
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-frontend::form.performance'))
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)
                        ->schema([
                            Checkbox::make('cache_enabled')
                                ->label(__('capell-frontend::form.cache_enabled'))
                                ->helperText(__('capell-frontend::form.cache_enabled_helper')),
                            TextInput::make('cache_ttl')
                                ->label(__('capell-frontend::form.cache_ttl'))
                                ->helperText(__('capell-frontend::form.cache_ttl_helper'))
                                ->integer()
                                ->minValue(1)
                                ->suffix(__('capell-frontend::form.seconds')),
                            Checkbox::make('minify_html')
                                ->label(__('capell-frontend::form.minify_html'))
                                ->helperText(__('capell-frontend::form.minify_html_helper')),
                            Checkbox::make('enable_static_generation')
                                ->label(__('capell-frontend::form.enable_static_generation'))
                                ->helperText(__('capell-frontend::form.enable_static_generation_helper')),
                            Checkbox::make('generate_sitemap')
                                ->label(__('capell-frontend::form.generate_sitemap'))
                                ->helperText(__('capell-frontend::form.generate_sitemap_helper')),
                            Checkbox::make('custom_error_page_enabled')
                                ->label(__('capell-frontend::form.custom_error_page_enabled'))
                                ->helperText(__('capell-frontend::form.custom_error_page_enabled_helper')),
                            Checkbox::make('custom_maintenance_page_enabled')
                                ->label(__('capell-frontend::form.custom_maintenance_page_enabled'))
                                ->helperText(__('capell-frontend::form.custom_maintenance_page_enabled_helper')),
                        ]),
                ]),
        ];
    }
}
