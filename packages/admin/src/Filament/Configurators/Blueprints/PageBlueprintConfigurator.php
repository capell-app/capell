<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Blueprints;

use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Components\Forms\AssetTypeSelect;
use Capell\Admin\Filament\Components\Forms\CacheFrequencySelect;
use Capell\Admin\Filament\Components\Forms\CacheTimeSelect;
use Capell\Admin\Filament\Components\Forms\ComponentSelect;
use Capell\Admin\Filament\Components\Forms\ConfiguratorSelect;
use Capell\Admin\Filament\Components\Forms\ContentStructureSelect;
use Capell\Admin\Filament\Components\Forms\IconPicker;
use Capell\Admin\Filament\Components\Forms\Page\UrlParamsRepeater;
use Capell\Admin\Filament\Components\Forms\RequiredFields;
use Capell\Core\Enums\ComponentTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Support\Media\ImageSourcePresets;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Override;
use Spatie\Permission\Models\Role;

class PageBlueprintConfigurator extends DefaultBlueprintConfigurator
{
    #[Override]
    public static function getKey(): string
    {
        return 'page';
    }

    /** @return array<int, mixed> */
    #[Override]
    protected function getFormSchema(Schema $schema): array
    {
        return [
            ...$this->settingsSchema($schema),
            Tabs::make()
                ->columnSpanFull()
                ->tabs([
                    $this->frontendTab(),
                    $this->adminTab(),
                    $this->settingsTab(),
                ]),
            ...$this->statusSchema(),
        ];
    }

    protected function adminTab(): Tab
    {
        return Tab::make(__('capell-admin::generic.admin'))
            ->key('admin')
            ->statePath('admin')
            ->icon(config('capell-admin.icon.admin'))
            ->columnSpanFull()
            ->columns()
            ->schema([
                $this->blueprintConfiguratorSelect(static::getKey()),
                ConfiguratorSelect::make('configurator')
                    ->label(__('capell-admin::form.admin_form_configurator'))
                    ->helperText(__('capell-admin::generic.admin_form_configurator_info'))
                    ->default(self::DEFAULT_ADMIN_CONFIGURATOR)
                    ->setupOptions(ConfiguratorTypeEnum::Page)
                    ->withCreateConfiguratorAction(ConfiguratorTypeEnum::Page),
                IconPicker::make('icon')
                    ->label(__('capell-admin::form.admin_icon'))
                    ->helperText(__('capell-admin::generic.admin_icon_info')),
                Textarea::make('notes')
                    ->label(__('capell-admin::form.description'))
                    ->helperText(__('capell-admin::generic.type_description_info'))
                    ->rows(2)
                    ->columnSpanFull(),
                AssetTypeSelect::make('asset_types')
                    ->multiple(),
                Select::make('image_source_policy.image')
                    ->label(__('capell-admin::form.image_source_policy'))
                    ->helperText(__('capell-admin::form.image_source_policy_helper'))
                    ->options(ImageSourcePresets::presetOptions())
                    ->placeholder(__('capell-admin::generic.default')),
                Section::make(__('capell-admin::form.page_type_access'))
                    ->description(__('capell-admin::generic.type_access_description'))
                    ->compact()
                    ->columnSpanFull()
                    ->schema([
                        RequiredFields::make(),
                        Select::make('role_restrictions')
                            ->label(__('capell-admin::form.role_restrictions'))
                            ->multiple()
                            ->columnSpanFull()
                            ->hidden(fn (): bool => auth()->user()?->can('manageRestrictions', Page::class) !== true)
                            ->dehydratedWhenHidden()
                            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'id')->toArray())
                            ->afterStateHydrated(function (Select $component, ?Blueprint $record): void {
                                if ($record instanceof Blueprint) {
                                    $component->state($record->getRestrictedRoleIds()->all());
                                }
                            }),
                    ]),
            ]);
    }

    protected function frontendTab(): Tab
    {
        return Tab::make(__('capell-admin::generic.frontend'))
            ->key('frontend')
            ->icon('heroicon-m-cog-6-tooth')
            ->columns()
            ->schema([
                Section::make(__('capell-admin::form.rendering'))
                    ->description(__('capell-admin::generic.type_rendering_description'))
                    ->compact()
                    ->columnSpanFull()
                    ->schema([
                        ComponentSelect::make('component')
                            ->setupType(ComponentTypeEnum::Page)
                            ->withCreateComponentAction()
                            ->withSourceFlow(),
                        CacheTimeSelect::make('meta.cache_time'),
                        CacheFrequencySelect::make('meta.cache_frequency'),
                    ]),
                Section::make(__('capell-admin::form.page_type_behaviour'))
                    ->description(__('capell-admin::generic.type_behaviour_description'))
                    ->compact()
                    ->columnSpanFull()
                    ->statePath('meta')
                    ->columns()
                    ->schema([
                        Checkbox::make('disable_visit_logs')
                            ->label(__('capell-admin::form.disable_visit_logs')),
                        Checkbox::make('accessible')
                            ->label(__('capell-admin::form.accessible'))
                            ->helperText(__('capell-admin::generic.page_accessible_info'))
                            ->afterStateHydrated(function (Checkbox $component, ?Blueprint $record): void {
                                if ($record instanceof Blueprint && $record->getMeta('accessible') === null) {
                                    $component->state(true);
                                }
                            })
                            ->default(true),
                        Checkbox::make('listable')
                            ->label(__('capell-admin::form.listable'))
                            ->helperText(__('capell-admin::generic.page_listable_info'))
                            ->afterStateHydrated(function (Checkbox $component, ?Blueprint $record): void {
                                if ($record instanceof Blueprint && $record->getMeta('listable') === null) {
                                    $component->state(true);
                                }
                            })
                            ->default(true),
                        Checkbox::make('sitemap')
                            ->label(__('capell-admin::form.sitemap'))
                            ->helperText(__('capell-admin::generic.page_sitemap_info'))
                            ->afterStateHydrated(function (Checkbox $component, ?Blueprint $record): void {
                                if ($record instanceof Blueprint && $record->getMeta('sitemap') === null) {
                                    $component->state(true);
                                }
                            })
                            ->default(true),
                        Checkbox::make('with_next_prev')
                            ->label(__('capell-admin::form.next_prev')),
                        Toggle::make('layout_editable')
                            ->label(__('capell-admin::form.layout_editable'))
                            ->afterStateHydrated(function (Toggle $component, ?Blueprint $record): void {
                                if ($record instanceof Blueprint && $record->getMeta('layout_editable') === null) {
                                    $component->state(true);
                                }
                            })
                            ->default(true),
                    ]),
            ]);
    }

    private function settingsTab(): Tab
    {
        return Tab::make(__('capell-admin::generic.settings'))
            ->key('settings')
            ->statePath('meta')
            ->icon('heroicon-m-cog')
            ->columns()
            ->schema([
                ContentStructureSelect::make('content_structure'),
                Section::make(__('capell-admin::generic.results'))
                    ->compact()
                    ->columns()
                    ->visible(
                        fn (Get $get): bool => $get('pagination') !== null
                            || $get('page_group') !== null
                            || $get('limit') !== null
                            || $get('columns') !== null,
                    )
                    ->schema([
                        TextInput::make('limit')
                            ->label(__('capell-admin::form.limit'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100),
                        TextInput::make('columns')
                            ->label(__('capell-admin::form.columns'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3),
                    ]),
                $this->getUrlParamsSection(),
            ]);
    }

    private function getUrlParamsSection(): Section
    {
        return Section::make(__('capell-admin::form.url_params'))
            ->description(__('capell-admin::generic.url_params_info'))
            ->collapsed()
            ->compact()
            ->columnSpanFull()
            ->icon(Heroicon::OutlinedSignal)
            ->schema([
                UrlParamsRepeater::make('url_params')
                    ->hiddenLabel()
                    ->columnSpanFull(),
            ]);

    }
}
