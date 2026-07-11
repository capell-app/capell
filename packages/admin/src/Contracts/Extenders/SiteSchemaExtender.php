<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Capell\Admin\Enums\SiteCreateWizardHookEnum;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

interface SiteSchemaExtender
{
    public const string TAG = SchemaExtenderEnum::Site->value;

    /**
     * Hook-based translation component injection for sites.
     *
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
     * Modify or add components inside the site meta details schema.
     *
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    public function extendSiteMetaDetailsComponents(Schema $schema, array $components): array;

    /**
     * Hook-based component injection for site create-wizard steps.
     *
     * @return array<int, Component>
     */
    public function extendCreateWizardComponentsForHook(Schema $schema, SiteCreateWizardHookEnum $hook): array;
}
