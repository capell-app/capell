<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Schemas;

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractPageSchemaExtender implements PageSchemaExtender
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

    public function extendSidebarComponents(Schema $schema): array
    {
        return [];
    }
}
