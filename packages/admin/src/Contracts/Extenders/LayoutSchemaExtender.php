<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Admin\Enums\SchemaExtenderEnum;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

interface LayoutSchemaExtender
{
    public const string TAG = SchemaExtenderEnum::Layout->value;

    /**
     * Modify or add relation managers.
     *
     * @param  array<int, mixed>  $relationManagers
     * @return array<int, mixed>
     */
    public function extendRelationManagers(Model $record, array $relationManagers): array;

    /**
     * Modify or add tabs for the edit page.
     *
     * @param  array<int, mixed>  $tabs
     * @return array<int, mixed>
     */
    public function extendTabs(Schema $schema, array $tabs): array;
}
