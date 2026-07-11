<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

interface PageSchemaExtender
{
    public const string TAG = SchemaExtenderEnum::Page->value;

    /**
     * @return array<int, Component>
     */
    public function extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array;

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

    /**
     * Contribute components to the page editor sidebar.
     *
     * @return array<int, Component>
     */
    public function extendSidebarComponents(Schema $schema): array;
}
