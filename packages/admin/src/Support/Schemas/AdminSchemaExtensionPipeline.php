<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Schemas;

use Capell\Admin\Contracts\Extenders\EditRecordHeadingExtender;
use Capell\Admin\Contracts\Extenders\LayoutSchemaExtender;
use Capell\Admin\Contracts\Extenders\PageHeaderActionExtender;
use Capell\Admin\Contracts\Extenders\PagePreviewActionExtender;
use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Contracts\Extenders\PageTitleWithSlugInputExtender;
use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Capell\Admin\Contracts\Extenders\SiteHeaderActionExtender;
use Capell\Admin\Contracts\Extenders\SiteSchemaExtender;
use Capell\Admin\Contracts\Extenders\UserSchemaExtender;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Enums\SiteCreateWizardHookEnum;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Support\Bridges\UserResourceBridgeResolver;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

final class AdminSchemaExtensionPipeline
{
    public function __construct(
        private readonly UserResourceBridgeResolver $userBridgeResolver = new UserResourceBridgeResolver,
    ) {}

    /**
     * @param  array<int, mixed>  $relationManagers
     * @return array<int, mixed>
     */
    public function pageRelationManagers(Model $record, array $relationManagers): array
    {
        foreach ($this->pageSchemaExtenders() as $extender) {
            $relationManagers = $extender->extendRelationManagers($record, $relationManagers);
        }

        return $relationManagers;
    }

    /**
     * @param  array<int, mixed>  $tabs
     * @return array<int, mixed>
     */
    public function pageTabs(Schema $schema, array $tabs): array
    {
        foreach ($this->pageSchemaExtenders() as $extender) {
            $tabs = $extender->extendTabs($schema, $tabs);
        }

        return $tabs;
    }

    /** @return array<int, Component> */
    public function pageSidebarComponents(Schema $schema): array
    {
        $components = [];

        foreach ($this->pageSchemaExtenders() as $extender) {
            $components = [...$components, ...$extender->extendSidebarComponents($schema)];
        }

        return $components;
    }

    /** @return array<int, Component> */
    public function pageTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array
    {
        $components = [];

        foreach ($this->pageSchemaExtenders() as $extender) {
            $components = [...$components, ...$extender->extendTranslationComponentsForHook($schema, $hook)];
        }

        return $components;
    }

    /** @return array<int, Action> */
    public function pageHeaderActions(): array
    {
        $actions = [];

        foreach ($this->tagged(PageHeaderActionExtender::TAG, PageHeaderActionExtender::class) as $extender) {
            $actions = [...$actions, ...$extender->actions()];
        }

        return $actions;
    }

    /** @return array<int, Action> */
    public function pagePreviewActions(): array
    {
        $actions = [];

        foreach ($this->tagged(PagePreviewActionExtender::TAG, PagePreviewActionExtender::class) as $extender) {
            $actions = [...$actions, ...$extender->actions()];
        }

        return $actions;
    }

    /** @return array<int, Action> */
    public function pageTitleActions(): array
    {
        $actions = [];

        foreach ($this->pageTitleExtenders() as $extender) {
            $actions = [...$actions, ...$extender->actions()];
        }

        return $actions;
    }

    public function pageTitleAfterLabel(FusedGroup $component): ?Schema
    {
        foreach ($this->pageTitleExtenders() as $extender) {
            $schema = $extender->afterLabel($component);

            if ($schema instanceof Schema) {
                return $schema;
            }
        }

        return null;
    }

    public function editRecordHeading(EditRecord $page, string|Htmlable $default): string|Htmlable
    {
        foreach ($this->tagged(EditRecordHeadingExtender::TAG, EditRecordHeadingExtender::class) as $extender) {
            if (! $extender->supports($page)) {
                continue;
            }

            return $extender->heading($page, $default);
        }

        return $default;
    }

    public function editRecordSaved(EditRecord $page): void
    {
        foreach ($this->tagged(EditRecordHeadingExtender::TAG, EditRecordHeadingExtender::class) as $extender) {
            if (! $extender->supports($page)) {
                continue;
            }

            $extender->saved($page);
        }
    }

    /**
     * @param  class-string  $pageClass
     * @return array<int, Action>
     */
    public function resourceHeaderActions(string $pageClass): array
    {
        $actions = [];

        foreach ($this->resourceHeaderExtenders() as $extender) {
            if (! $extender->supports($pageClass)) {
                continue;
            }

            $actions = [...$actions, ...$extender->actions()];
        }

        return $actions;
    }

    /**
     * @param  array<int, mixed>  $relationManagers
     * @return array<int, mixed>
     */
    public function layoutRelationManagers(Model $record, array $relationManagers): array
    {
        foreach ($this->layoutSchemaExtenders() as $extender) {
            $relationManagers = $extender->extendRelationManagers($record, $relationManagers);
        }

        return $relationManagers;
    }

    /**
     * @param  array<int, mixed>  $tabs
     * @return array<int, mixed>
     */
    public function layoutTabs(Schema $schema, array $tabs): array
    {
        foreach ($this->layoutSchemaExtenders() as $extender) {
            $tabs = $extender->extendTabs($schema, $tabs);
        }

        return $tabs;
    }

    /**
     * @param  array<int, mixed>  $relationManagers
     * @return array<int, mixed>
     */
    public function siteRelationManagers(Model $record, array $relationManagers): array
    {
        foreach ($this->siteSchemaExtenders() as $extender) {
            $relationManagers = $extender->extendRelationManagers($record, $relationManagers);
        }

        return $relationManagers;
    }

    /**
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    public function siteTranslationComponents(Schema $schema, array $components): array
    {
        return [
            ...$this->siteTranslationComponentsForHook($schema, PageTranslationSchemaHookEnum::BeforeTitle),
            ...$components,
            ...$this->siteTranslationComponentsForHook($schema, PageTranslationSchemaHookEnum::AfterTitle),
        ];
    }

    /** @return array<int, Component> */
    public function siteTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array
    {
        $components = [];

        foreach ($this->siteSchemaExtenders() as $extender) {
            $components = [...$components, ...$extender->extendTranslationComponentsForHook($schema, $hook)];
        }

        return $components;
    }

    /**
     * @param  array<int, mixed>  $tabs
     * @return array<int, mixed>
     */
    public function siteTabs(Schema $schema, array $tabs): array
    {
        foreach ($this->siteSchemaExtenders() as $extender) {
            $tabs = $extender->extendTabs($schema, $tabs);
        }

        return $tabs;
    }

    /**
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    public function siteMetaDetailsComponents(Schema $schema, array $components): array
    {
        foreach ($this->siteSchemaExtenders() as $extender) {
            $components = $extender->extendSiteMetaDetailsComponents($schema, $components);
        }

        return $components;
    }

    /** @return array<int, Component> */
    public function siteCreateWizardComponentsForHook(Schema $schema, SiteCreateWizardHookEnum $hook): array
    {
        $components = [];

        foreach ($this->siteSchemaExtenders() as $extender) {
            $components = [...$components, ...$extender->extendCreateWizardComponentsForHook($schema, $hook)];
        }

        return $components;
    }

    /** @return array<int, Action> */
    public function siteHeaderActions(): array
    {
        $actions = [];

        foreach ($this->tagged(SiteHeaderActionExtender::TAG, SiteHeaderActionExtender::class) as $extender) {
            $actions = [...$actions, ...$extender->actions()];
        }

        return $actions;
    }

    /** @return array<int, Component> */
    public function userComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
    {
        return [
            ...$this->legacyUserComponentsForHook($schema, $hook, $context),
            ...$this->userBridgeResolver->resolveComponentsForHook($schema, $hook, $context),
        ];
    }

    /** @return array<int, Component> */
    public function userSidebarComponents(Schema $schema, UserSchemaContextData $context): array
    {
        return [
            ...$this->legacyUserSidebarComponents($schema, $context),
            ...$this->userBridgeResolver->resolveSidebarComponents($schema, $context),
        ];
    }

    /**
     * @param  array<int, mixed>  $relationManagers
     * @return array<int, mixed>
     */
    public function userRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
    {
        foreach ($this->supportedUserSchemaExtenders($context) as $extender) {
            $relationManagers = $extender->extendRelationManagers($record, $relationManagers, $context);
        }

        return $this->userBridgeResolver->resolveRelationManagers($record, $relationManagers, $context);
    }

    /**
     * @template TExtender of object
     *
     * @param  class-string<TExtender>  $type
     * @return array<int, TExtender>
     */
    private function tagged(string $tag, string $type): array
    {
        $resolvedExtenders = [];

        foreach (app()->tagged($tag) as $extender) {
            if (! $extender instanceof $type) {
                continue;
            }

            $resolvedExtenders[spl_object_id($extender)] = $extender;
        }

        return array_values($resolvedExtenders);
    }

    /** @return array<int, PageSchemaExtender> */
    private function pageSchemaExtenders(): array
    {
        return $this->tagged(PageSchemaExtender::TAG, PageSchemaExtender::class);
    }

    /** @return array<int, PageTitleWithSlugInputExtender> */
    private function pageTitleExtenders(): array
    {
        return $this->tagged(PageTitleWithSlugInputExtender::TAG, PageTitleWithSlugInputExtender::class);
    }

    /** @return array<int, ResourceHeaderActionExtender> */
    private function resourceHeaderExtenders(): array
    {
        return $this->tagged(ResourceHeaderActionExtender::TAG, ResourceHeaderActionExtender::class);
    }

    /** @return array<int, LayoutSchemaExtender> */
    private function layoutSchemaExtenders(): array
    {
        return $this->tagged(LayoutSchemaExtender::TAG, LayoutSchemaExtender::class);
    }

    /** @return array<int, SiteSchemaExtender> */
    private function siteSchemaExtenders(): array
    {
        return $this->tagged(SiteSchemaExtender::TAG, SiteSchemaExtender::class);
    }

    /** @return array<int, Component> */
    private function legacyUserComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
    {
        $components = [];

        foreach ($this->supportedUserSchemaExtenders($context) as $extender) {
            $components = [...$components, ...$extender->extendComponentsForHook($schema, $hook, $context)];
        }

        return $components;
    }

    /** @return array<int, Component> */
    private function legacyUserSidebarComponents(Schema $schema, UserSchemaContextData $context): array
    {
        $components = [];

        foreach ($this->supportedUserSchemaExtenders($context) as $extender) {
            $components = [...$components, ...$extender->extendSidebarComponents($schema, $context)];
        }

        return $components;
    }

    /** @return array<int, UserSchemaExtender> */
    private function supportedUserSchemaExtenders(UserSchemaContextData $context): array
    {
        return array_values(array_filter(
            $this->tagged(UserSchemaExtender::TAG, UserSchemaExtender::class),
            fn (UserSchemaExtender $extender): bool => $extender->supports($context),
        ));
    }
}
