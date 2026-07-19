<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Filament\Components\Forms\DefaultToggle;
use Capell\Admin\Filament\Components\Forms\LanguageSelect;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Filament\Resources\Sites\RelationManagers\SiteDomainsRelationManager;
use Capell\Core\Enums\UrlScheme;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SiteDomainForm implements FormConfigurator
{
    public ?Site $ownerRecord = null;

    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema->components(self::getFormSchema());
    }

    /** @return array<int, mixed> */
    protected static function getFormSchema(): array
    {
        return [
            Section::make(__('capell-admin::form.site_domain_url'))
                ->description(__('capell-admin::generic.site_domain_url_description'))
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3, 'lg' => 5])
                        ->schema([
                            Select::make('scheme')
                                ->label(__('capell-admin::form.url_scheme'))
                                ->placeholder(__('capell-admin::generic.both'))
                                ->options(UrlScheme::class)
                                ->validationAttribute(__('capell-admin::generic.schema')),

                            TextInput::make('domain')
                                ->label(__('capell-admin::form.domain'))
                                ->required()
                                ->default(parse_url(url()->current(), PHP_URL_HOST))
                                ->columnSpan(['lg' => 2]),

                            TextInput::make('path')
                                ->label(__('capell-admin::form.url_path'))
                                ->helperText(__('capell-admin::generic.site_url_path_info'))
                                ->maxLength(32)
                                ->columnSpan(['lg' => 2]),
                        ]),
                ]),
            Section::make(__('capell-admin::form.site_domain_availability'))
                ->description(__('capell-admin::generic.site_domain_availability_description'))
                ->columnSpanFull()
                ->schema([
                    Grid::make()
                        ->schema([
                            LanguageSelect::make('language_id')
                                ->withRelationship()
                                ->required(),

                            DefaultToggle::make('default')
                                ->visible(
                                    fn (SiteDomainsRelationManager $livewire, ?SiteDomain $record): bool => $record?->default ||
                                        ! $livewire->ownerRecord->hasDefaultDomain(),
                                )
                                ->default(true),

                            StatusToggle::make('status'),
                        ]),
                ]),
        ];
    }
}
