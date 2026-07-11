<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Settings;

use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Core\Enums\ImageSourceType;
use Capell\Core\Support\Media\ImageSourcePresets;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CoreSettingsSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-admin::form.settings_core_language'))
                ->columnSpanFull()
                ->description(__('capell-admin::generic.settings_core_language_info'))
                ->schema([
                    TextInput::make('default_locale')
                        ->label(__('capell-admin::form.default_locale'))
                        ->helperText(__('capell-admin::form.default_locale_helper'))
                        ->default(config('app.locale')),
                ]),
            Section::make(__('capell-admin::form.settings_core_image_policy'))
                ->columnSpanFull()
                ->description(__('capell-admin::generic.settings_core_image_policy_info'))
                ->columns()
                ->schema([
                    Select::make('allowed_image_sources')
                        ->label(__('capell-admin::form.allowed_image_sources'))
                        ->helperText(__('capell-admin::form.allowed_image_sources_helper'))
                        ->options(ImageSourcePresets::presetOptions())
                        ->default('all')
                        ->required(),
                    Select::make('default_image_source')
                        ->label(__('capell-admin::form.default_image_source'))
                        ->helperText(__('capell-admin::form.default_image_source_helper'))
                        ->options([
                            ImageSourceType::Url->value => ImageSourceType::Url->getLabel(),
                            ImageSourceType::Upload->value => ImageSourceType::Upload->getLabel(),
                            ImageSourceType::Media->value => ImageSourceType::Media->getLabel(),
                        ])
                        ->default(ImageSourceType::Media->value)
                        ->in([
                            ImageSourceType::Url->value,
                            ImageSourceType::Upload->value,
                            ImageSourceType::Media->value,
                        ])
                        ->required(),
                    TagsInput::make('allowed_remote_image_domains')
                        ->label(__('capell-admin::form.allowed_remote_image_domains'))
                        ->helperText(__('capell-admin::form.allowed_remote_image_domains_helper'))
                        ->placeholder('images.unsplash.com'),
                    Checkbox::make('allow_relative_image_urls')
                        ->label(__('capell-admin::form.allow_relative_image_urls'))
                        ->helperText(__('capell-admin::form.allow_relative_image_urls_helper'))
                        ->default(true),
                ]),
        ];
    }
}
