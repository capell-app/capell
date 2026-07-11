<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Schemas;

use Capell\Admin\Contracts\Extenders\UserSchemaExtender;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractUserSchemaExtender implements UserSchemaExtender
{
    public function supports(UserSchemaContextData $context): bool
    {
        return true;
    }

    public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
    {
        return [];
    }

    public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
    {
        return [];
    }

    public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
    {
        return $relationManagers;
    }
}
