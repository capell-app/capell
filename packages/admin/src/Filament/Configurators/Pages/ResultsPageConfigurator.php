<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Pages;

use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Filament\Components\Forms\ContentEditor;
use Capell\Admin\Filament\Components\Forms\ExtraContentSection;
use Capell\Admin\Filament\Components\Forms\FixedWidthSidebar;
use Capell\Admin\Filament\Components\Forms\Page\SettingsSchema;
use Capell\Admin\Filament\Components\Forms\Page\TitleWithSlugInput;
use Capell\Admin\Filament\Components\Forms\Page\TranslationsRepeater;
use Capell\Admin\Filament\Components\Forms\PublishDatesGrid;
use Capell\Admin\Filament\Components\Forms\RepeaterTabs;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Override;

class ResultsPageConfigurator extends DefaultPageConfigurator
{
    #[Override]
    protected function getEditFormSchema(Schema $schema): array
    {
        return [
            FixedWidthSidebar::make()
                ->mainSchema([
                    $this->getTranslationFormSchema($schema),
                ])
                ->sidebarSchema(
                    [
                        $this->publishPanel($schema),
                        Section::make()
                            ->gridContainer()
                            ->columns(['@md' => 2])
                            ->schema(SettingsSchema::make($schema)),
                    ],
                ),
            Tabs::make()
                ->columnSpanFull()
                ->tabs($this->getTabs($schema)),
        ];
    }

    #[Override]
    protected function getEditOptionFormSchema(Schema $schema): array
    {
        return [
            $this->getTranslationFormSchema($schema),
            Section::make(__('capell-admin::generic.settings'))
                ->columns()
                ->compact()
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->collapsed()
                ->schema([
                    ...SettingsSchema::make($schema),
                    PublishDatesGrid::getVisibleFromField(),
                ]),
        ];
    }

    #[Override]
    protected function getTranslationFormSchema(Schema $schema): RepeaterTabs
    {
        $components = [
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::BeforeTitle),
            TitleWithSlugInput::make($schema),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::AfterTitle),
            $this->getContentEditor($schema),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::AfterContentEditor),
            ExtraContentSection::make()
                ->statePath('meta')
                ->schema([
                    TextInput::make('label')
                        ->label(__('capell-admin::form.label'))
                        ->hintIcon(Heroicon::QuestionMarkCircle, tooltip: __('capell-admin::generic.label_info')),
                    ContentEditor::make('no_results')
                        ->label(__('capell-admin::form.no_results'))
                        ->hint(__('capell-admin::generic.no_results_info')),
                ]),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::AfterExtraContent),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::BeforeSearchMeta),
            ...$this->getSearchMetaComponents(),
            ...$this->resolveTranslationHookComponents($schema, PageTranslationSchemaHookEnum::AfterSearchMeta),
        ];

        return TranslationsRepeater::make('translations')
            ->columnSpanFull()
            ->schema($components)
            ->contained(fn (string $operation): bool => in_array($operation, ['create', 'edit'], true));
    }

    #[Override]
    protected function getTabs(Schema $schema): array
    {
        return $this->resolvePageTabs($schema, []);
    }
}
