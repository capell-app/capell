<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Sites;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Contracts\Extenders\SiteSchemaExtender;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Capell\Admin\Enums\SiteCreateWizardHookEnum;
use Capell\Admin\Filament\Components\Forms\FixedWidthSidebar;
use Capell\Admin\Filament\Components\Forms\ImageIconUpload;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Filament\Components\Forms\PageSelect;
use Capell\Admin\Filament\Components\Forms\Site\DefaultPagesCheckboxList;
use Capell\Admin\Filament\Components\Forms\Site\DomainsRepeater;
use Capell\Admin\Filament\Components\Forms\Site\DomainsSchema;
use Capell\Admin\Filament\Components\Forms\Site\LanguageSelect;
use Capell\Admin\Filament\Components\Forms\Site\LanguagesSchema;
use Capell\Admin\Filament\Components\Forms\Site\SettingsSchema;
use Capell\Admin\Filament\Components\Forms\Site\SiteTranslationsRepeater;
use Capell\Admin\Filament\Components\Forms\Site\Wizard\DetailsStep;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Filament\Components\Forms\SocialIcons;
use Capell\Admin\Filament\Components\Forms\TranslationLanguageSelect;
use Capell\Admin\Filament\Concerns\HasConfigurator;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\CapellCoreHelper;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DefaultSiteConfigurator implements ConfiguratorInterface
{
    use HasConfigurator;

    protected static ConfiguratorTypeEnumInterface $configuratorType = ConfiguratorTypeEnum::Site;

    /** @return array<int, mixed> */
    public static function relationManagers(Model $record): array
    {
        return resolve(AdminSchemaExtensionPipeline::class)
            ->siteRelationManagers($record, []);
    }

    /** @return iterable<int, SiteSchemaExtender> */
    public static function getExtenders(): iterable
    {
        return app()->tagged(SchemaExtenderEnum::Site->value);
    }

    /** @return array<int, mixed> */
    public function make(Schema $schema): array
    {
        return match ($schema->getOperation()) {
            'create', 'createOption', 'replicate' => $this->getCreateFormSchema($schema),
            default => $this->getEditFormSchema($schema),
        };
    }

    /** @return array<int, mixed> */
    protected function getCreateFormSchema(Schema $schema): array
    {
        return [
            Wizard::make([
                $this->makeDetailsStep($schema),
                $this->makeDomainsStep(),
                $this->makePagesStep(),
            ])
                ->extraAttributes([
                    'class' => 'minimal-wizard',
                ])
                ->columnSpanFull()
                ->submitAction($this->getSubmitFormAction())
                ->contained(in_array($schema->getOperation(), ['create', 'edit'], true)),
        ];
    }

    /** @return array<int, mixed> */
    protected function getEditFormSchema(Schema $schema): array
    {
        $components = $this->makeEditFormComponents($schema);

        return [
            FixedWidthSidebar::make()
                ->mainSchema([
                    $this->makeSiteContentSection($schema, $components),
                    $this->makeSiteRelationshipsSection(),
                    $this->makeSiteContactSection($schema),
                    $this->makeSiteBrandSection(),
                    $this->makeSiteMediaSection(),
                ])
                ->sidebarSchema(
                    SettingsSchema::make(
                        $schema,
                        components: [
                            LanguageSelect::make('language_id'),
                            $this->makeRequiredTranslations(),
                        ],
                    ),
                    contained: true,
                ),
        ];
    }

    protected function getSubmitFormAction(): Action
    {
        return Action::make('create')
            ->label(__('filament-panels::resources/pages/create-record.form.actions.create.label'))
            ->submit('create')
            ->keyBindings(['mod+s']);
    }

    private function makeDetailsStep(Schema $schema): Step
    {
        return DetailsStep::make('details')
            ->columns()
            ->schema(
                SettingsSchema::make(
                    $schema,
                    LanguagesSchema::make(),
                ),
            );
    }

    private function makeDomainsStep(): Step
    {
        return Step::make(__('capell-admin::tab.domains'))
            ->description(__('capell-admin::generic.site_domains_description'))
            ->schema([
                DomainsRepeater::make()
                    ->relationship('siteDomains')
                    ->schema(DomainsSchema::make()),
            ]);
    }

    private function makePagesStep(): Step
    {
        return Step::make(__('capell-admin::tab.pages'))
            ->description(__('capell-admin::generic.create_default_pages'))
            ->schema([
                Section::make(__('capell-admin::form.setup_pages'))
                    ->description(__('capell-admin::generic.setup_pages_info'))
                    ->compact()
                    ->schema([
                        DefaultPagesCheckboxList::make('auto_create_pages')
                            ->label(__('capell-admin::form.default_pages'))
                            ->helperText(__('capell-admin::generic.default_pages_helper'))
                            ->dehydrated(false)
                            ->columns(['md' => 2, 'default' => 1, 'lg' => 3, 'xl' => 4])
                            ->columnSpanFull(),
                        Repeater::make('pages')
                            ->label(__('capell-admin::form.pages'))
                            ->helperText(__('capell-admin::generic.custom_pages_helper'))
                            ->hiddenLabel()
                            ->addActionLabel(__('capell-admin::button.add_page'))
                            ->dehydrated(false)
                            ->defaultItems(0)
                            ->simple(
                                TextInput::make('name')
                                    ->label(__('capell-admin::form.name'))
                                    ->helperText(__('capell-admin::generic.custom_page_name_helper'))
                                    ->required()
                                    ->distinct()
                                    ->placeholder(__('capell-admin::form.page_name_placeholder')),
                            ),
                    ]),
                ...resolve(AdminSchemaExtensionPipeline::class)
                    ->siteCreateWizardComponentsForHook(Schema::make(), SiteCreateWizardHookEnum::PagesStepEnd),
            ]);
    }

    /** @return array<int, Component> */
    private function makeEditFormComponents(Schema $schema): array
    {
        $components = [
            TextInput::make('title')
                ->label(__('capell-admin::form.title'))
                ->columnSpan(2)
                ->requiredBasedOnType(),

            TranslationLanguageSelect::make()
                ->dehydratedWhenHidden()
                ->withRelationship()
                ->hidden(fn (?int $state): bool => (bool) $state),

            Group::make()
                ->statePath('meta')
                ->hidden(fn (Get $get): bool => $get('../../language_id') === null || $get('../../language_id') === '')
                ->schema([
                    TextInput::make('label')
                        ->label(__('capell-admin::form.label'))
                        ->helperText(__('capell-admin::generic.alternate_name')),
                ]),
        ];

        return [
            ...resolve(AdminSchemaExtensionPipeline::class)
                ->siteTranslationComponentsForHook($schema, PageTranslationSchemaHookEnum::BeforeTitle),
            ...$components,
            ...resolve(AdminSchemaExtensionPipeline::class)
                ->siteTranslationComponentsForHook($schema, PageTranslationSchemaHookEnum::AfterTitle),
        ];
    }

    /**
     * @param  array<int, Component>  $components
     */
    private function makeSiteContentSection(Schema $schema, array $components): Section
    {
        return Section::make(__('capell-admin::tab.content'))
            ->description(__('capell-admin::generic.site_content_description'))
            ->schema([
                SiteTranslationsRepeater::make($schema)
                    ->schema([
                        Grid::make(3)
                            ->columnSpanFull()
                            ->schema($components),
                    ]),
            ]);
    }

    private function makeSiteRelationshipsSection(): Section
    {
        return Section::make(__('capell-admin::form.related_sites'))
            ->description(__('capell-admin::generic.related_sites_description'))
            ->statePath('meta')
            ->schema([
                SiteSelect::make('related')
                    ->label(__('capell-admin::form.related_sites'))
                    ->hiddenLabel()
                    ->modifyQueryUsing(
                        fn (?Site $record, Builder $query) => $record instanceof Site
                            ? $query->whereKeyNot($record->getKey())
                            : $query,
                    )
                    ->multiple(),
            ]);
    }

    private function makeSiteContactSection(Schema $schema): Section
    {
        $components = [
            TextInput::make('business_name')
                ->label(__('capell-admin::form.business_name'))
                ->helperText(__('capell-admin::generic.business_name_info')),
            TextInput::make('email')
                ->label(__('capell-admin::form.email'))
                ->email(),
            TextInput::make('phone')
                ->label(__('capell-admin::form.phone'))
                ->tel(),
            PageSelect::make('contact_page_id')
                ->label(__('capell-admin::form.contact_page'))
                ->helperText(__('capell-admin::generic.contact_page_info')),
            Textarea::make('footer_content')
                ->label(__('capell-admin::form.footer_copy'))
                ->helperText(__('capell-admin::generic.footer_copy_info'))
                ->rows(3)
                ->columnSpanFull(),
        ];

        return Section::make(__('capell-admin::generic.contact'))
            ->description(__('capell-admin::generic.site_contact_description'))
            ->statePath('meta')
            ->columnSpanFull()
            ->columns(2)
            ->schema(resolve(AdminSchemaExtensionPipeline::class)
                ->siteMetaDetailsComponents($schema, $components));
    }

    private function makeSiteBrandSection(): Section
    {
        return Section::make(__('capell-admin::generic.brand'))
            ->description(__('capell-admin::generic.site_brand_description'))
            ->statePath('meta')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                ColorPicker::make('brand_color')
                    ->label(__('capell-admin::form.brand_color'))
                    ->helperText(__('capell-admin::generic.brand_color_info'))
                    ->autoFormat(),
                TextInput::make('twitter')
                    ->label(__('capell-admin::form.twitter_handle'))
                    ->helperText(__('capell-admin::generic.twitter_handle_info'))
                    ->placeholder('@yourhandle')
                    ->maxLength(50),
                SocialIcons::make('social_links')
                    ->columnSpanFull(),
            ]);
    }

    private function makeRequiredTranslations(): CheckboxList
    {
        return CheckboxList::make('admin.require_translations')
            ->label(__('capell-admin::form.required_translations'))
            ->options(
                function (): array {
                    /** @var class-string<Language> $model */
                    $model = Language::class;

                    return $model::query()
                        ->ordered()
                        ->pluck('name', 'code')
                        ->toArray();
                },
            )
            ->columns()
            ->gridDirection(GridDirection::Row)
            ->disableOptionWhen(
                function (Get $get, ?Site $record, string $value): bool {
                    $translations = $get('../../translations');

                    $siteLanguageIds = collect(is_array($translations) ? $translations : [])
                        ->pluck('language_id')
                        ->filter()
                        ->toArray();

                    $defaultLanguageId = $get('../../language_id');
                    if (filled($defaultLanguageId)) {
                        $siteLanguageIds[] = (int) $defaultLanguageId;
                    }

                    if ($record instanceof Site) {
                        $siteLanguageIds[] = $record->language_id;
                    }

                    $siteLanguages = CapellCoreHelper::getLanguageCodesByIds($siteLanguageIds);

                    return ! in_array($value, $siteLanguages, true);
                },
            );
    }

    private function makeSiteMediaSection(): Section
    {
        return Section::make(__('capell-admin::generic.media'))
            ->description(__('capell-admin::generic.site_media_description'))
            ->columnSpanFull()
            ->statePath('meta')
            ->columns(2)
            ->schema([
                MediaLibraryFileUpload::make('image')
                    ->label(__('capell-admin::form.image'))
                    ->columnSpanFull()
                    ->helperText(__('capell-admin::generic.showcase_image')),
                MediaLibraryFileUpload::make('logo')
                    ->label(__('capell-admin::form.logo')),
                Toggle::make('mail.use_site_logo')
                    ->label(__('capell-admin::form.mail_use_site_logo'))
                    ->helperText(__('capell-admin::form.mail_use_site_logo_helper'))
                    ->default(true),
                MediaLibraryFileUpload::make('logo_inverted')
                    ->label(__('capell-admin::form.logo_inverted'))
                    ->helperText(__('capell-admin::generic.hint_inverted_image')),
                ImageIconUpload::make('icon')
                    ->label(__('capell-admin::form.icon'))
                    ->disk('public')
                    ->directory('site'),
                ImageIconUpload::make('favicon')
                    ->label(__('capell-admin::form.favicon'))
                    ->disk('public')
                    ->directory('site'),
            ]);
    }
}
