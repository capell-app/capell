<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site\Tab;

use Capell\Admin\Contracts\Extenders\SiteSchemaExtender;
use Capell\Admin\Filament\Components\Forms\PageSelect;
use Capell\Admin\Filament\Components\Forms\Site\LanguageSelect;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Filament\Components\Forms\SocialIcons;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DetailsTab
{
    /**
     * @param  array<int, mixed>  $components
     */
    public static function make(Schema $schema, array $components = []): Tab
    {
        return Tab::make(__('capell-admin::tab.details'))
            ->icon('heroicon-o-identification')
            ->columns()
            ->schema([
                ...$components,

                Group::make()
                    ->statePath('meta')
                    ->columnSpanFull()
                    ->schema([
                        Section::make(__('capell-admin::generic.contact'))
                            ->compact()
                            ->columns()
                            ->schema(function () use ($schema): array {
                                $sectionComponents = [
                                    TextInput::make('business_name')
                                        ->label(__('capell-admin::form.business_name')),
                                    TextInput::make('email')
                                        ->label(__('capell-admin::form.email'))
                                        ->email(),
                                    TextInput::make('phone')
                                        ->label(__('capell-admin::form.phone'))
                                        ->tel(),
                                    PageSelect::make('contact_page_id')
                                        ->label(__('capell-admin::form.contact_page')),
                                ];

                                return resolve(AdminSchemaExtensionPipeline::class)
                                    ->siteMetaDetailsComponents($schema, $sectionComponents);
                            }),

                        Section::make(__('capell-admin::generic.brand'))
                            ->compact()
                            ->columns()
                            ->schema([
                                ColorPicker::make('brand_color')
                                    ->label(__('capell-admin::form.brand_color'))
                                    ->autoFormat(),
                                TextInput::make('twitter')
                                    ->label(__('capell-admin::form.twitter_handle'))
                                    ->placeholder('@yourhandle')
                                    ->maxLength(50)
                                    ->dehydrated(static fn (): bool => ! CapellCore::isPackageInstalled('capell-app/socials'))
                                    ->visible(static fn (): bool => ! CapellCore::isPackageInstalled('capell-app/socials')),
                            ]),
                    ]),

                LanguageSelect::make('language_id'),

                Group::make()
                    ->statePath('meta')
                    ->schema([
                        SiteSelect::make('related')
                            ->label(__('capell-admin::form.related_sites'))
                            ->modifyQueryUsing(
                                fn (?Site $record, Builder $query) => $record instanceof Site
                                    ? $query->whereKeyNot($record->getKey())
                                    : $query,
                            )
                            ->multiple(),
                    ]),

                Group::make()
                    ->statePath('meta')
                    ->columnSpanFull()
                    ->schema([
                        Section::make(__('capell-admin::generic.online_presence'))
                            ->compact()
                            ->schema(function () use ($schema): array {
                                $sectionComponents = [
                                    SocialIcons::make('social_links')
                                        ->dehydrated(static fn (): bool => ! CapellCore::isPackageInstalled('capell-app/socials'))
                                        ->visible(static fn (): bool => ! CapellCore::isPackageInstalled('capell-app/socials')),
                                ];

                                return collect(app()->tagged(SiteSchemaExtender::TAG))
                                    ->reduce(
                                        fn (array $carry, SiteSchemaExtender $extender): array => $extender->extendSiteMetaDetailsComponents($schema, $carry),
                                        $sectionComponents,
                                    );
                            }),
                    ]),
            ]);
    }
}
