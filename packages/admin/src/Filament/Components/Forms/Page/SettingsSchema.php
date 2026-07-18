<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Enums\PageHeroAssetSourceEnum;
use Capell\Admin\Enums\PageHeroStyleEnum;
use Capell\Core\Contracts\Pageable;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SettingsSchema
{
    /**
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    public static function make(
        Schema $schema,
        array $components = [],
        string|Closure $pageGroup = 'page',
        ?Closure $modifyParentQueryUsing = null,
        bool $withLayout = true,
        bool $withParent = true,
        bool $withSite = true,
        bool $withType = true,
        bool $withHero = true,
    ): array {
        return [
            NameInput::make('name')
                ->withTitleUpdater(),

            ...($withSite ? [
                SiteSelect::make(),
            ] : []),

            ...($withParent ? [
                self::parentField($schema, $pageGroup, $modifyParentQueryUsing),
            ] : []),

            ...($withType ? [
                self::blueprintField($schema, $pageGroup),
            ] : []),

            ...($withLayout ? [
                LayoutSelect::make('layout_id')
                    ->reactive(),
            ] : []),

            self::orderField(),

            ...($withHero ? [
                self::heroSettingsSection(),
            ] : []),

            ...$components,
        ];
    }

    /**
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    public static function pageContext(
        Schema $schema,
        array $components = [],
        string|Closure $pageGroup = 'page',
        ?Closure $modifyParentQueryUsing = null,
        bool $withParent = true,
    ): array {
        return [
            ...($withParent ? [
                self::parentField($schema, $pageGroup, $modifyParentQueryUsing),
            ] : []),

            ...$components,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function pageConfiguration(
        Schema $schema,
        string|Closure $pageGroup = 'page',
        bool $withLayout = true,
        bool $withSite = true,
        bool $withType = true,
    ): array {
        return [
            NameInput::make('name')
                ->withTitleUpdater(),

            ...($withSite ? [
                SiteSelect::make(),
            ] : []),

            ...($withType ? [
                self::blueprintField($schema, $pageGroup),
            ] : []),

            ...($withLayout ? [
                LayoutSelect::make('layout_id')
                    ->reactive(),
            ] : []),

            self::orderField(),
        ];
    }

    public static function heroSettingsSection(): Section
    {
        return Section::make(__('capell-admin::form.hero_settings'))
            ->compact()
            ->collapsed()
            ->columnSpanFull()
            ->schema(self::heroSettingsFields());
    }

    /**
     * @return array<int, mixed>
     */
    private static function heroSettingsFields(): array
    {
        return [
            Checkbox::make('meta.show_hero')
                ->label(__('capell-admin::form.show_hero'))
                ->helperText(__('capell-admin::form.show_hero_helper'))
                ->default(true),
            Checkbox::make('meta.header_over_hero')
                ->label(__('capell-admin::form.header_over_hero'))
                ->helperText(__('capell-admin::form.page_header_over_hero_helper')),
            Select::make('meta.hero_style')
                ->label(__('capell-admin::form.hero_style'))
                ->helperText(__('capell-admin::form.hero_style_helper'))
                ->options(PageHeroStyleEnum::options())
                ->default('default'),
            TextInput::make('meta.hero_height')
                ->label(__('capell-admin::form.hero_height'))
                ->helperText(__('capell-admin::form.hero_height_helper'))
                ->placeholder('min(760px, 92vh)')
                ->maxLength(64),
            Select::make('meta.hero_asset_source')
                ->label(__('capell-admin::form.hero_asset_source'))
                ->helperText(__('capell-admin::form.hero_asset_source_helper'))
                ->options(PageHeroAssetSourceEnum::options())
                ->default('element'),
        ];
    }

    private static function parentField(
        Schema $schema,
        string|Closure $pageGroup = 'page',
        ?Closure $modifyParentQueryUsing = null,
    ): ParentSelect {
        return ParentSelect::make('parent_id')
            ->label(__('capell-admin::form.parent_page'))
            ->reactive()
            ->withHintEditAction()
            ->setupRelation('parent', $schema)
            ->pageGroup($modifyParentQueryUsing instanceof Closure ? $modifyParentQueryUsing : $pageGroup);
    }

    private static function blueprintField(Schema $schema, string|Closure $pageGroup = 'page'): BlueprintSelect
    {
        return BlueprintSelect::make('blueprint_id')
            ->pageGroup($pageGroup)
            ->withRelation()
            ->withSystemTypes(function (?Pageable $record, Get $get): bool {
                if ($record instanceof Pageable && $record->blueprint->isSystem()) {
                    return true;
                }

                return (bool) $get('with_system_types');
            })
            ->when(
                $schema->isCreating(),
                fn (BlueprintSelect $component): BlueprintSelect => $component->withCreateForm(),
                fn (BlueprintSelect $component): BlueprintSelect => $component->withEditForm(),
            );
    }

    private static function orderField(): TextInput
    {
        return TextInput::make('order')
            ->label(__('capell-admin::form.order'))
            ->numeric()
            ->minValue(0)
            ->step(1);
    }
}
