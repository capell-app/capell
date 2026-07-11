<?php

declare(strict_types=1);

namespace Capell\Frontend\Filament\Settings;

use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Admin\Filament\Support\HelperText;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FrontendSettingsSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-frontend::form.performance'))
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)
                        ->schema([
                            HelperText::apply(
                                Checkbox::make('cache_enabled')
                                    ->label(__('capell-frontend::form.cache_enabled')),
                                'capell-frontend::form.cache_enabled_helper',
                            ),
                            TextInput::make('cache_ttl')
                                ->label(__('capell-frontend::form.cache_ttl'))
                                ->helperText(__('capell-frontend::form.cache_ttl_helper'))
                                ->integer()
                                ->minValue(1)
                                ->suffix(__('capell-frontend::form.seconds')),
                            HelperText::apply(
                                Checkbox::make('minify_html')
                                    ->label(__('capell-frontend::form.minify_html')),
                                'capell-frontend::form.minify_html_helper',
                            ),
                            HelperText::apply(
                                Checkbox::make('enable_static_generation')
                                    ->label(__('capell-frontend::form.enable_static_generation')),
                                'capell-frontend::form.enable_static_generation_helper',
                            ),
                            HelperText::apply(
                                Checkbox::make('generate_sitemap')
                                    ->label(__('capell-frontend::form.generate_sitemap')),
                                'capell-frontend::form.generate_sitemap_helper',
                            ),
                            HelperText::apply(
                                Checkbox::make('custom_error_page_enabled')
                                    ->label(__('capell-frontend::form.custom_error_page_enabled')),
                                'capell-frontend::form.custom_error_page_enabled_helper',
                            ),
                            HelperText::apply(
                                Checkbox::make('custom_maintenance_page_enabled')
                                    ->label(__('capell-frontend::form.custom_maintenance_page_enabled')),
                                'capell-frontend::form.custom_maintenance_page_enabled_helper',
                            ),
                        ]),
                ]),
        ];
    }
}
