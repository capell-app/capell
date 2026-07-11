<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Schemas;

use Capell\Admin\Contracts\Extenders\SiteSchemaExtender;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Enums\SiteCreateWizardHookEnum;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSiteSchemaExtender implements SiteSchemaExtender
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

    public function extendSiteMetaDetailsComponents(Schema $schema, array $components): array
    {
        return $components;
    }

    public function extendCreateWizardComponentsForHook(Schema $schema, SiteCreateWizardHookEnum $hook): array
    {
        return [];
    }
}
