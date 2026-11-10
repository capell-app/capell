<?php

declare(strict_types=1);

namespace Capell\ExtensionCookbook\Filament\Extenders;

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

final class ExtensionCookbookPageSchemaExtender implements PageSchemaExtender
{
    public function extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array
    {
        return [];
    }

    public function extendRelationManagers(Model $record, array $relationManagers): array
    {
        return $relationManagers;
    }

    public function extendTabs(Schema $schema, array $tabs): array
    {
        return $tabs;
    }

    /** @return array<int, Component> */
    public function extendSidebarComponents(Schema $schema): array
    {
        return [Text::make(__('capell-extension-cookbook::admin.schema.help'))];
    }
}
