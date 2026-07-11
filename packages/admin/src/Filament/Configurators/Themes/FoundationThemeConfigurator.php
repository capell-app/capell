<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Themes;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Contracts\Extenders\ThemeSchemaExtender;
use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Capell\Admin\Filament\Components\Forms\DefaultToggle;
use Capell\Admin\Filament\Components\Forms\IconPicker;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Components\Forms\Theme\DetailsSchema;
use Capell\Admin\Filament\Concerns\HasConfigurator;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Capell\Admin\Support\Themes\ThemeEditorExtensionRegistry;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class FoundationThemeConfigurator implements ConfiguratorInterface
{
    use HasConfigurator;

    protected static ConfiguratorTypeEnumInterface $configuratorType = ConfiguratorTypeEnum::Theme;

    public static function getKey(): string
    {
        return 'Default';
    }

    /** @return iterable<int, mixed> */
    public static function getExtenders(): iterable
    {
        return app()->tagged(SchemaExtenderEnum::Theme->value);
    }

    /** @return array<int, mixed> */
    public function make(Schema $schema): array
    {
        return [
            ...DetailsSchema::make($schema),
            Hidden::make('blueprint_id')
                ->default(fn (): int|string|null => $schema->isCreating() ? $this->resolveTypeId() : null)
                ->dehydrated($schema->isCreating()),
            Grid::make(['@lg' => 5])
                ->columnSpanFull()
                ->schema([
                    Grid::make()
                        ->columnSpan(['@lg' => 3])
                        ->schema([
                            $this->quickSetupSection($schema),
                            $this->brandSection(),
                            $this->headerSection(),
                            $this->surfaceSection(),
                            $this->footerSection(),
                            $this->assetsSection(),
                            $this->advancedSection($schema),
                            ...$this->packageExtensionSections($schema),
                        ]),
                    Section::make(__('capell-admin::theme-library.actions.preview'))
                        ->columnSpan(['@lg' => 2])
                        ->schema([
                            Grid::make(['@sm' => 2])
                                ->schema([
                                    Select::make('admin.editor.preview.device')
                                        ->label(__('capell-admin::theme-editor.fields.preview_device'))
                                        ->options([
                                            'desktop' => __('capell-admin::theme-editor.options.desktop'),
                                            'tablet' => __('capell-admin::theme-editor.options.tablet'),
                                            'mobile' => __('capell-admin::theme-editor.options.mobile'),
                                        ])
                                        ->default('desktop')
                                        ->live()
                                        ->native(false),
                                    Select::make('admin.editor.preview.colorMode')
                                        ->label(__('capell-admin::theme-editor.fields.preview_color_mode'))
                                        ->options([
                                            'light' => __('capell-admin::theme-editor.options.light'),
                                            'dark' => __('capell-admin::theme-editor.options.dark'),
                                        ])
                                        ->default('light')
                                        ->live()
                                        ->native(false),
                                ]),
                            View::make('capell-admin::filament.forms.theme-editor-preview'),
                        ]),
                ]),
            Grid::make()
                ->columnSpanFull()
                ->schema([
                    DefaultToggle::make('default'),
                    StatusToggle::make('status')
                        ->inline(),
                ]),
        ];
    }

    protected function quickSetupSection(Schema $schema): Section
    {
        return Section::make(__('capell-admin::theme-editor.sections.quick_setup'))
            ->description(__('capell-admin::theme-editor.descriptions.quick_setup'))
            ->columns(['@sm' => 2])
            ->schema([
                Select::make('meta.editor.preset.active')
                    ->label(__('capell-admin::theme-library.labels.presets'))
                    ->options(fn (): array => $this->presetOptions($this->schemaRecord($schema)))
                    ->default(fn (): string => array_key_first($this->presetOptions($this->schemaRecord($schema))) ?? 'default')
                    ->helperText(__('capell-admin::theme-library.help.preset'))
                    ->dehydrated(fn (): bool => $this->presetOptions($this->schemaRecord($schema)) !== [])
                    ->live()
                    ->native(false),
                IconPicker::make('admin.editor.icon'),
                Textarea::make('admin.editor.description')
                    ->label(__('capell-admin::form.theme_description'))
                    ->helperText(__('capell-admin::form.theme_description_helper'))
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
                MediaLibraryFileUpload::make('admin.editor.image')
                    ->label(__('capell-admin::form.preview_image')),
            ]);
    }

    protected function brandSection(): Section
    {
        return Section::make(__('capell-admin::theme-editor.sections.brand'))
            ->description(__('capell-admin::theme-editor.descriptions.brand'))
            ->statePath('meta.editor.brand')
            ->columns(['@sm' => 2])
            ->schema([
                ColorPicker::make('primaryColor')
                    ->label(__('capell-admin::theme-editor.fields.primary_color'))
                    ->default('#0f766e')
                    ->hex()
                    ->hexColor()
                    ->live(debounce: 400),
                ColorPicker::make('accentColor')
                    ->label(__('capell-admin::theme-editor.fields.accent_color'))
                    ->default('#f59e0b')
                    ->hex()
                    ->hexColor()
                    ->live(debounce: 400),
                ColorPicker::make('neutralColor')
                    ->label(__('capell-admin::theme-editor.fields.neutral_color'))
                    ->default('#111827')
                    ->hex()
                    ->hexColor()
                    ->live(debounce: 400),
                Select::make('radius')
                    ->label(__('capell-admin::theme-editor.fields.radius'))
                    ->options([
                        'none' => __('capell-admin::generic.none'),
                        'sm' => __('capell-admin::generic.sm'),
                        'md' => __('capell-admin::generic.md'),
                        'lg' => __('capell-admin::generic.lg'),
                        'xl' => 'XL',
                    ])
                    ->default('md')
                    ->live(),
                TextInput::make('headingFont')
                    ->label(__('capell-admin::theme-editor.fields.heading_font'))
                    ->default('inter')
                    ->live(debounce: 400),
                TextInput::make('bodyFont')
                    ->label(__('capell-admin::theme-editor.fields.body_font'))
                    ->default('inter')
                    ->live(debounce: 400),
            ]);
    }

    protected function headerSection(): Section
    {
        return Section::make(__('capell-admin::theme-editor.sections.header'))
            ->description(__('capell-admin::theme-editor.descriptions.header'))
            ->statePath('meta.editor.header')
            ->columns(['@sm' => 2])
            ->schema([
                Checkbox::make('enabled')
                    ->label(__('capell-admin::theme-editor.fields.enabled'))
                    ->default(true)
                    ->live(),
                Select::make('position')
                    ->label(__('capell-admin::theme-editor.fields.position'))
                    ->options([
                        'static' => __('capell-admin::theme-editor.options.static'),
                        'sticky' => __('capell-admin::theme-editor.options.sticky'),
                        'fixed' => __('capell-admin::theme-editor.options.fixed'),
                    ])
                    ->live(),
                Checkbox::make('overHero')
                    ->label(__('capell-admin::theme-editor.fields.over_hero'))
                    ->live(),
                TextInput::make('component')
                    ->label(__('capell-admin::theme-editor.fields.component'))
                    ->live(debounce: 400),
            ]);
    }

    protected function surfaceSection(): Section
    {
        return Section::make(__('capell-admin::theme-editor.sections.surface'))
            ->description(__('capell-admin::theme-editor.descriptions.surface'))
            ->statePath('meta.editor.surface')
            ->columns(['@sm' => 2])
            ->schema([
                ColorPicker::make('surfaceColor')
                    ->label(__('capell-admin::theme-editor.fields.surface_color'))
                    ->hex()
                    ->hexColor()
                    ->live(debounce: 400),
                ColorPicker::make('foregroundColor')
                    ->label(__('capell-admin::theme-editor.fields.foreground_color'))
                    ->hex()
                    ->hexColor()
                    ->live(debounce: 400),
                Select::make('container')
                    ->label(__('capell-admin::form.container'))
                    ->options([
                        'sm' => __('capell-admin::generic.sm'),
                        'md' => __('capell-admin::generic.md'),
                        'lg' => __('capell-admin::generic.lg'),
                    ])
                    ->default('lg')
                    ->live(),
                Select::make('cardDensity')
                    ->label(__('capell-admin::theme-editor.fields.card_density'))
                    ->options([
                        'compact' => __('capell-admin::theme-editor.options.compact'),
                        'comfortable' => __('capell-admin::theme-editor.options.comfortable'),
                    ])
                    ->live(),
            ]);
    }

    protected function footerSection(): Section
    {
        return Section::make(__('capell-admin::theme-editor.sections.footer'))
            ->description(__('capell-admin::theme-editor.descriptions.footer'))
            ->statePath('meta.editor.footer')
            ->columns(['@sm' => 2])
            ->schema([
                Checkbox::make('enabled')
                    ->label(__('capell-admin::theme-editor.fields.enabled'))
                    ->default(true)
                    ->live(),
                TextInput::make('component')
                    ->label(__('capell-admin::theme-editor.fields.component'))
                    ->live(debounce: 400),
                Textarea::make('copy')
                    ->label(__('capell-admin::theme-editor.fields.footer_copy'))
                    ->rows(2)
                    ->columnSpanFull()
                    ->live(debounce: 400),
            ]);
    }

    protected function assetsSection(): Section
    {
        return Section::make(__('capell-admin::theme-editor.sections.assets'))
            ->description(__('capell-admin::theme-editor.descriptions.assets'))
            ->statePath('meta.editor.assets')
            ->schema([
                Textarea::make('paths')
                    ->label(__('capell-admin::theme-editor.fields.asset_paths'))
                    ->helperText(__('capell-admin::theme-editor.help.asset_paths'))
                    ->dehydrateStateUsing(fn (mixed $state): array => collect(explode("\n", is_string($state) ? $state : ''))
                        ->map(fn (string $asset): string => trim($asset))
                        ->filter()
                        ->values()
                        ->all())
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode("\n", $state) : (string) $state)
                    ->live(debounce: 400),
                TextInput::make('buildPath')
                    ->label(__('capell-admin::theme-editor.fields.build_path'))
                    ->helperText(__('capell-admin::theme-editor.help.build_path'))
                    ->live(debounce: 400),
            ]);
    }

    protected function advancedSection(Schema $schema): Section
    {
        $components = [
            TextInput::make('mainClass')
                ->label(__('capell-admin::form.main_class')),
            Checkbox::make('roundedImages')
                ->label(__('capell-admin::form.rounded_images'))
                ->inline(),
            CodeEditor::make('metaTags')
                ->label(__('capell-admin::form.meta_tags'))
                ->columnSpanFull(),
            CodeEditor::make('customCss')
                ->label(__('capell-admin::form.custom_css'))
                ->columnSpanFull()
                ->live(debounce: 500),
        ];

        foreach (static::getExtenders() as $extender) {
            if ($extender instanceof ThemeSchemaExtender) {
                $components = $extender->extendSettingsComponents($schema, $components);
            }
        }

        return Section::make(__('capell-admin::theme-editor.sections.advanced'))
            ->description(__('capell-admin::theme-editor.descriptions.advanced'))
            ->statePath('meta.editor.advanced')
            ->columns(['@sm' => 2])
            ->schema($components);
    }

    /** @return array<int, Section> */
    protected function packageExtensionSections(Schema $schema): array
    {
        $record = $schema->getRecord();

        if (! $record instanceof Theme) {
            return [];
        }

        $context = ThemeEditorContextData::forTheme($record, $this->themeDefinition($record));

        return resolve(ThemeEditorExtensionRegistry::class)
            ->forContext($context)
            ->flatMap(fn (mixed $extension): array => $extension->editorSections($context))
            ->filter(fn (mixed $section): bool => $section instanceof Section)
            ->values()
            ->all();
    }

    protected function resolveTypeId(): int|string|null
    {
        $resolver = resolve(ConfiguratorResolver::class);

        if (filled($this->context?->typeKey)) {
            return $resolver->resolveTypeByKey($this->context->typeKey, ConfiguratorTypeEnum::Theme)->getKey();
        }

        return $resolver->resolveDefaultType(ConfiguratorTypeEnum::Theme)->getKey();
    }

    /** @return array<string, string> */
    private function presetOptions(?Model $record): array
    {
        if (! $record instanceof Theme) {
            return ['default' => 'Default'];
        }

        return $this->themeDefinition($record)?->presetOptions() ?: ['default' => 'Default'];
    }

    private function schemaRecord(Schema $schema): ?Model
    {
        $record = $schema->getRecord();

        return $record instanceof Model ? $record : null;
    }

    private function themeDefinition(Theme $theme): ?ThemeDefinitionData
    {
        if (! app()->bound(ThemeRegistry::class)) {
            return null;
        }

        $registry = resolve(ThemeRegistry::class);

        if (! $registry->has($theme->key)) {
            return null;
        }

        return $registry->definition($theme->key);
    }
}
