<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Pages;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Filament\Components\Forms\CallToActionText;
use Capell\Admin\Filament\Components\Forms\ExtraContentSection;
use Capell\Admin\Filament\Components\Forms\FixedWidthSidebar;
use Capell\Admin\Filament\Components\Forms\ImageSourcePicker;
use Capell\Admin\Filament\Components\Forms\Page\ContentEditor;
use Capell\Admin\Filament\Components\Forms\Page\CreatePageSchema;
use Capell\Admin\Filament\Components\Forms\Page\LayoutSelect;
use Capell\Admin\Filament\Components\Forms\Page\SettingsSchema;
use Capell\Admin\Filament\Components\Forms\Page\TitleWithSlugInput;
use Capell\Admin\Filament\Components\Forms\Page\TranslationsRepeater;
use Capell\Admin\Filament\Components\Forms\PublishDatesGrid;
use Capell\Admin\Filament\Components\Forms\PublishSchema;
use Capell\Admin\Filament\Components\Forms\RepeaterTabs;
use Capell\Admin\Filament\Concerns\HasConfigurator;
use Capell\Admin\Filament\Concerns\HasDefaultRelationManagers;
use Capell\Admin\Filament\Livewire\PublishStatusPanel;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnhandledMatchError;

class DefaultPageConfigurator implements ConfiguratorInterface
{
    use HasConfigurator;
    use HasDefaultRelationManagers {
        HasDefaultRelationManagers::relationManagers as protected baseRelationManagers;
    }

    protected static ConfiguratorTypeEnumInterface $configuratorType = ConfiguratorTypeEnum::Page;

    protected bool $hasCreatePageSchema = true;

    /** @return array<int, mixed> */
    public static function relationManagers(Model $record): array
    {
        $relationManagers = $record instanceof Page ? static::baseRelationManagers($record) : [];

        return resolve(AdminSchemaExtensionPipeline::class)
            ->pageRelationManagers($record, $relationManagers);
    }

    /** @return iterable<int, PageSchemaExtender> */
    public static function getExtenders(): iterable
    {
        return app()->tagged(PageSchemaExtender::TAG);
    }

    /** @return array<int, mixed> */
    public function make(Schema $schema): array
    {
        return match ($this->operation($schema)) {
            'create', 'createOption', 'replicate' => $this->getCreateFormSchema($schema),
            'edit' => $this->getEditFormSchema($schema),
            'editOption' => $this->getEditOptionFormSchema($schema),
            default => throw new UnhandledMatchError('Unsupported page schema operation.'),
        };
    }

    protected function operation(Schema $schema): string
    {
        return $this->context->operation ?? $schema->getOperation();
    }

    /**
     * Tagged component replacers: callables (Schema,array)->array used to replace or modify component arrays.
     * Tag name: 'capell-admin:schema-component-replacers:page'
     *
     * @return iterable<int, mixed>
     */
    protected function getComponentReplacers(): iterable
    {
        return app()->tagged('capell-admin:schema-component-replacers:page');
    }

    /**
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    protected function applyReplacers(Schema $schema, array $components): array
    {
        foreach ($this->getComponentReplacers() as $replacer) {
            if (is_callable($replacer)) {
                $components = (array) $replacer($schema, $components);
            }
        }

        return $components;
    }

    protected function getContentEditor(Schema $schema): Component
    {
        // Prefer the page's effective content structure (override-aware) over
        // the raw blueprint default — a per-page mode override needs to route
        // to the matching editor.
        $record = $schema->getRecord();
        if ($record instanceof Page) {
            return ContentEditor::make(structure: $record->content_structure);
        }

        $type = $this->resolveBlueprintForSchema($schema);

        return ContentEditor::make(structure: $type?->content_structure);
    }

    protected function resolveBlueprintForSchema(Schema $schema): ?Blueprint
    {
        $record = $schema->getRecord();

        if ($record instanceof Model && $record->relationLoaded('blueprint')) {
            $relation = $record->getRelation('blueprint');
            $type = $relation instanceof Blueprint ? $relation : null;
        } elseif (filled($this->context?->typeKey)) {
            $type = resolve(ConfiguratorResolver::class)->resolveTypeByKey(
                $this->context->typeKey,
                ConfiguratorTypeEnum::Page,
                $this->context->resourceName,
            );
        } else {
            $type = Page::getDefaultType($this->context?->resourceName);
        }

        return $type;
    }

    /**
     * @return list<string>|string|null
     */
    protected function blueprintImageSourcePolicy(Schema $schema, string $field): string|array|null
    {
        $policy = data_get($this->resolveBlueprintForSchema($schema)?->admin, 'image_source_policy.' . $field);

        if (is_string($policy)) {
            return $policy;
        }

        if (is_array($policy)) {
            return array_values(array_filter($policy, is_string(...)));
        }

        return null;
    }

    /** @return array<int, mixed> */
    protected function getCreateFormSchema(Schema $schema): array
    {
        return [
            ...($this->hasCreatePageSchema ? CreatePageSchema::make($schema, $this->context) : []),
            $this->getTranslationFormSchema($schema),
            Section::make()
                ->columns()
                ->columnSpanFull()
                ->heading(__('capell-admin::form.publish_setup'))
                ->description(__('capell-admin::generic.publish_setup_description'))
                ->schema($this->getCreateExtraFor($schema))
                ->contained(in_array($this->operation($schema), ['create', 'edit'], true)),
        ];
    }

    /** @return array<int, mixed> */
    protected function getCreateExtraFor(Schema $schema): array
    {
        return [
            Group::make([
                LayoutSelect::make('layout_id')
                    ->helperText(__('capell-admin::generic.page_layout_select_info'))
                    ->reactive()
                    ->modifyQueryUsing(
                        fn (Builder $query, Get $get): Builder => $query->when(
                            $get('system_pages'),
                            fn (Builder $query): Builder => $query->where(
                                fn (Builder $query) => $query->where('group', '!=', BlueprintGroupEnum::System->value)
                                    ->orWhereNull('group'),
                            ),
                        ),
                    ),
            ]),
            PublishSchema::make($schema),
        ];
    }

    /** @return array<int, mixed> */
    protected function getEditFormSchema(Schema $schema): array
    {
        $isFocusedSystemPage = $this->isFocusedSystemPage($schema);
        $tabs = $this->applyReplacers($schema, $this->getTabs($schema));

        if ($isFocusedSystemPage) {
            return [
                FixedWidthSidebar::make()
                    ->mainSchema([
                        $this->getTranslationFormSchema($schema),
                    ])
                    ->sidebarSchema([
                        $this->publishPanel($schema),
                        Section::make(__('capell-admin::generic.settings'))
                            ->gridContainer()
                            ->columns(['@md' => 2])
                            ->schema([
                                ...SettingsSchema::make(
                                    $schema,
                                    withLayout: false,
                                    withParent: false,
                                    withType: false,
                                    withHero: false,
                                ),
                            ]),
                    ]),
            ];
        }

        return [
            FixedWidthSidebar::make()
                ->mainSchema([
                    $this->getTranslationFormSchema($schema),
                    SettingsSchema::heroSettingsSection(),
                    Section::make(__('capell-admin::generic.page_configuration'))
                        ->compact()
                        ->collapsed()
                        ->columnSpanFull()
                        ->columns(['default' => 1, '@lg' => 2])
                        ->schema([
                            ...SettingsSchema::pageConfiguration($schema),
                        ]),
                ])
                ->sidebarSchema([
                    $this->publishPanel($schema),
                    Section::make(__('capell-admin::generic.page_context'))
                        ->gridContainer()
                        ->compact()
                        ->columns(1)
                        ->schema([
                            ...SettingsSchema::pageContext(
                                $schema,
                                [
                                    $this->featuredImageField($schema),
                                ],
                            ),
                        ]),
                    ...$this->resolvePageSidebarComponents($schema),
                ]),
            ...($tabs === [] ? [] : [
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs($tabs),
            ]),
        ];
    }

    /**
     * The WordPress-style publish panel, pinned to the top of the editor
     * sidebar. Replaces the old collapsible PublishSection form card; the panel
     * owns the publish lifecycle as a standalone Livewire component.
     */
    protected function publishPanel(Schema $schema): Livewire
    {
        $record = $schema->getRecord();

        return Livewire::make(PublishStatusPanel::class, [
            'recordClass' => Page::class,
            'recordId' => (int) ($record instanceof Model ? $record->getKey() : 0),
        ]);
    }

    protected function featuredImageField(?Schema $schema = null): Component
    {
        $field = ImageSourcePicker::make('image')
            ->sourceStatePath('meta.image_source');

        if ($schema instanceof Schema) {
            $field->imageSourcePolicy(blueprintSources: $this->blueprintImageSourcePolicy($schema, 'image'));
        }

        return $field;
    }

    /** @return array<int, mixed> */
    protected function getEditOptionFormSchema(Schema $schema): array
    {
        return [
            $this->getTranslationFormSchema($schema),
            Section::make(__('capell-admin::generic.settings'))
                ->compact()
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->schema([
                    ...SettingsSchema::make(
                        $schema,
                        [
                            ImageSourcePicker::make('image')
                                ->sourceStatePath('meta.image_source')
                                ->imageSourcePolicy(blueprintSources: $this->blueprintImageSourcePolicy($schema, 'image')),
                        ],
                    ),
                    // The editOption quick-edit modal can't host the full Livewire
                    // panel cleanly, so keep a slim inline publish-date field here.
                    PublishDatesGrid::getVisibleFromField(),
                ]),
        ];
    }

    protected function getTranslationFormSchema(Schema $schema): RepeaterTabs
    {
        if ($this->isFocusedSystemPage($schema)) {
            return TranslationsRepeater::make('translations')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('title')
                        ->label(__('capell-admin::form.page_title'))
                        ->required()
                        ->maxLength(255),
                    $this->getContentEditor($schema),
                ])
                ->contained(fn (string $operation): bool => in_array($operation, ['create', 'edit'], true));
        }

        $components = [
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::BeforeTitle),
            TitleWithSlugInput::make($schema),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::AfterTitle),
            $this->getContentEditor($schema),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::AfterContentEditor),
            ExtraContentSection::make()
                ->statePath('meta')
                ->schema([
                    Textarea::make('summary')
                        ->label(__('capell-admin::form.summary'))
                        ->helperText(__('capell-admin::generic.summary_info'))
                        ->rows(2)
                        ->maxLength(160),
                    Group::make([
                        TextInput::make('label')
                            ->label(__('capell-admin::form.label'))
                            ->hintIcon(Heroicon::QuestionMarkCircle, tooltip: __('capell-admin::generic.label_info')),
                        CallToActionText::make('link_text'),
                    ]),
                ]),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::AfterExtraContent),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::BeforeSearchMeta),
            ...$this->getSearchMetaComponents(),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::AfterSearchMeta),
        ];

        $components = $this->applyReplacers($schema, $components);

        return TranslationsRepeater::make('translations')
            ->columnSpanFull()
            ->schema($components)
            ->contained(fn (string $operation): bool => in_array($operation, ['create', 'edit'], true));
    }

    /** @return array<int, mixed> */
    protected function resolveTranslationHookComponents(Schema $schema, PageTranslationSchemaHookEnum $hook): array
    {
        return resolve(AdminSchemaExtensionPipeline::class)->pageTranslationComponentsForHook($schema, $hook);
    }

    /**
     * @return array<int, Component>
     */
    protected function getSearchMetaComponents(): array
    {
        return [
            Section::make(__('capell-admin::tab.seo_settings'))
                ->statePath('meta')
                ->collapsed()
                ->compact()
                ->columns()
                ->columnSpanFull()
                ->schema([
                    TextInput::make('title')
                        ->label(__('capell-admin::form.meta_title.label'))
                        ->helperText(__('capell-admin::form.meta_title.helper'))
                        ->placeholder(':site')
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label(__('capell-admin::form.meta_description.label'))
                        ->helperText(__('capell-admin::form.meta_description.helper'))
                        ->rows(3)
                        ->maxLength(320),
                    TextInput::make('keywords')
                        ->label(__('capell-admin::form.meta_keywords.label'))
                        ->helperText(__('capell-admin::form.meta_keywords.helper'))
                        ->maxLength(255),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    protected function getTabs(Schema $schema): array
    {
        return $this->resolvePageTabs($schema, []);
    }

    /**
     * @param  array<int, mixed>  $tabs
     * @return array<int, mixed>
     */
    protected function resolvePageTabs(Schema $schema, array $tabs): array
    {
        return resolve(AdminSchemaExtensionPipeline::class)->pageTabs($schema, $tabs);
    }

    /**
     * @return array<int, Component>
     */
    protected function resolvePageSidebarComponents(Schema $schema): array
    {
        return resolve(AdminSchemaExtensionPipeline::class)->pageSidebarComponents($schema);
    }

    protected function isFocusedSystemPage(Schema $schema): bool
    {
        $record = $schema->getRecord();

        if (! $record instanceof Page) {
            return false;
        }

        $type = $record->getRelationValue('blueprint');

        if (! $type instanceof Blueprint) {
            return false;
        }

        return in_array($type->key, [
            PageTypeEnum::Maintenance->value,
            PageTypeEnum::NotFound->value,
        ], true);
    }
}
