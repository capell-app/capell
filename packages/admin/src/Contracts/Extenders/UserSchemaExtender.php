<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

interface UserSchemaExtender
{
    public const string TAG = SchemaExtenderEnum::User->value;

    public function supports(UserSchemaContextData $context): bool;

    /**
     * @return array<int, Component>
     */
    public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array;

    /**
     * @return array<int, Component>
     */
    public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array;

    /**
     * @param  array<int, mixed>  $relationManagers
     * @return array<int, mixed>
     */
    public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array;
}
