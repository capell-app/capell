<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Settings;

use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MarketplaceSettingsSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-marketplace::settings.owner_contact'))
                ->columnSpanFull()
                ->description(__('capell-marketplace::settings.owner_contact_description'))
                ->schema([
                    TextInput::make('owner_name')
                        ->label(__('capell-marketplace::settings.owner_name'))
                        ->helperText(__('capell-marketplace::settings.owner_name_helper'))
                        ->maxLength(255),
                    TextInput::make('owner_email')
                        ->label(__('capell-marketplace::settings.owner_email'))
                        ->helperText(__('capell-marketplace::settings.owner_email_helper'))
                        ->email()
                        ->nullable()
                        ->maxLength(255),
                    TextInput::make('owner_organisation')
                        ->label(__('capell-marketplace::settings.owner_organisation'))
                        ->helperText(__('capell-marketplace::settings.owner_organisation_helper'))
                        ->maxLength(255),
                ])
                ->columns(2),
            Section::make(__('capell-marketplace::settings.notification_preferences'))
                ->columnSpanFull()
                ->description(__('capell-marketplace::settings.notification_preferences_description'))
                ->schema([
                    Checkbox::make('security_notifications_enabled')
                        ->label(__('capell-marketplace::settings.security_notifications'))
                        ->helperText(__('capell-marketplace::settings.security_notifications_helper'))
                        ->default(false),
                    Checkbox::make('bug_notifications_enabled')
                        ->label(__('capell-marketplace::settings.bug_notifications'))
                        ->helperText(__('capell-marketplace::settings.bug_notifications_helper'))
                        ->default(false),
                    Checkbox::make('marketing_notifications_enabled')
                        ->label(__('capell-marketplace::settings.marketing_notifications'))
                        ->helperText(__('capell-marketplace::settings.marketing_notifications_helper'))
                        ->default(false),
                ])
                ->columns(1),
        ];
    }
}
