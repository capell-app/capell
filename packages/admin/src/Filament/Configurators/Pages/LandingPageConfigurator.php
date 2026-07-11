<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Pages;

use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Filament\Components\Forms\CollapsibleTabs;
use Capell\Admin\Filament\Components\Forms\FixedWidthSidebar;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Filament\Components\Forms\Page\CreatePageSchema;
use Capell\Admin\Filament\Components\Forms\Page\LayoutSelect;
use Capell\Admin\Filament\Components\Forms\Page\SettingsSchema;
use Capell\Admin\Filament\Components\Forms\Page\TitleWithSlugInput;
use Capell\Admin\Filament\Components\Forms\Page\TranslationsRepeater;
use Capell\Admin\Filament\Components\Forms\PublishDatesGrid;
use Capell\Admin\Filament\Components\Forms\PublishSchema;
use Capell\Admin\Filament\Components\Forms\RepeaterTabs;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Override;
use UnhandledMatchError;

class LandingPageConfigurator extends DefaultPageConfigurator
{
    #[Override]
    public function make(Schema $schema): array
    {
        return match ($this->operation($schema)) {
            'create', 'createOption', 'replicate' => $this->getCreateFormSchema($schema),
            'edit' => $this->getEditFormSchema($schema),
            'editOption' => $this->getEditOptionFormSchema($schema),
            default => throw new UnhandledMatchError('Unsupported landing page schema operation.'),
        };
    }

    #[Override]
    protected function getCreateFormSchema(Schema $schema): array
    {
        return [
            ...($this->hasCreatePageSchema ? CreatePageSchema::make($schema) : []),
            $this->getTranslationFormSchema($schema),
            Section::make()
                ->columnSpanFull()
                ->columns()
                ->schema([
                    LayoutSelect::make('layout_id')
                        ->reactive(),
                    PublishSchema::make($schema),
                ])
                ->contained(in_array($this->operation($schema), ['create', 'edit'], true)),
        ];
    }

    #[Override]
    protected function getEditFormSchema(Schema $schema): array
    {
        return [
            FixedWidthSidebar::make()
                ->mainSchema([
                    $this->getTranslationFormSchema($schema),
                ])
                ->sidebarSchema([
                    $this->publishPanel($schema),
                    Section::make()
                        ->gridContainer()
                        ->columns(['@md' => 2])
                        ->schema(SettingsSchema::make($schema)),
                    $this->featuredImageField(),
                ]),
            CollapsibleTabs::make()
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
                    ...SettingsSchema::make(
                        $schema,
                        [
                            MediaLibraryFileUpload::make('image'),
                        ],
                    ),
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
