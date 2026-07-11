<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
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
use Capell\Admin\Support\Schemas\AbstractUserSchemaExtender;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Admin\Tests\Unit\Support\Schemas\Fixtures\PipelineBridgeRelationManager;
use Capell\Admin\Tests\Unit\Support\Schemas\Fixtures\PipelineExistingRelationManager;
use Capell\Admin\Tests\Unit\Support\Schemas\Fixtures\PipelinePageRelationManagerA;
use Capell\Admin\Tests\Unit\Support\Schemas\Fixtures\PipelinePageRelationManagerB;
use Capell\Admin\Tests\Unit\Support\Schemas\Fixtures\PipelineResourcePage;
use Capell\Admin\Tests\Unit\Support\Schemas\Fixtures\PipelineSiteRelationManagerA;
use Capell\Admin\Tests\Unit\Support\Schemas\Fixtures\PipelineUserRelationManagerLegacy;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

function capellPipelinePageExtender(string $suffix): PageSchemaExtender
{
    return new readonly class($suffix) implements PageSchemaExtender
    {
        public function __construct(private string $suffix) {}

        public function extendRelationManagers(Model $record, array $relationManagers): array
        {
            return [...$relationManagers, capellPipelineRelationManager('page', $this->suffix)];
        }

        public function extendTabs(Schema $schema, array $tabs): array
        {
            return [...$tabs, 'page-tab-' . $this->suffix];
        }

        public function extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array
        {
            return [TextInput::make('page_' . $hook->value . '_' . $this->suffix)];
        }

        public function extendSidebarComponents(Schema $schema): array
        {
            return [Text::make('page-sidebar-' . $this->suffix)];
        }
    };
}

function capellPipelineSiteExtender(string $suffix): SiteSchemaExtender
{
    return new readonly class($suffix) implements SiteSchemaExtender
    {
        public function __construct(private string $suffix) {}

        public function extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array
        {
            return [TextInput::make('site_' . $hook->value . '_' . $this->suffix)];
        }

        public function extendRelationManagers(Model $record, array $relationManagers): array
        {
            return [...$relationManagers, capellPipelineRelationManager('site', $this->suffix)];
        }

        public function extendTabs(Schema $schema, array $tabs): array
        {
            return [...$tabs, 'site-tab-' . $this->suffix];
        }

        public function extendSiteMetaDetailsComponents(Schema $schema, array $components): array
        {
            return [...$components, TextInput::make('site_meta_' . $this->suffix)];
        }

        public function extendCreateWizardComponentsForHook(Schema $schema, SiteCreateWizardHookEnum $hook): array
        {
            return [TextInput::make('site_wizard_' . $hook->value . '_' . $this->suffix)];
        }
    };
}

function capellPipelineUserExtender(string $suffix, bool $supports = true): UserSchemaExtender
{
    return new class($suffix, $supports) extends AbstractUserSchemaExtender
    {
        public function __construct(private readonly string $suffix, private readonly bool $supportsContext) {}

        public function supports(UserSchemaContextData $context): bool
        {
            return $this->supportsContext;
        }

        public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
        {
            return [TextInput::make('user_' . $hook->value . '_' . $this->suffix)];
        }

        public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
        {
            return [TextInput::make('user_sidebar_' . $this->suffix)];
        }

        public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
        {
            return [...$relationManagers, capellPipelineRelationManager('user', $this->suffix)];
        }
    };
}

function capellPipelineUserBridge(string $suffix, bool $supports = true): UserResourceBridge
{
    return new readonly class($suffix, $supports) implements UserResourceBridge
    {
        public function __construct(private string $suffix, private bool $supportsContext) {}

        public function supports(UserSchemaContextData $context): bool
        {
            return $this->supportsContext;
        }

        public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
        {
            return [TextInput::make('bridge_' . $hook->value . '_' . $this->suffix)];
        }

        public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
        {
            return [TextInput::make('bridge_sidebar_' . $this->suffix)];
        }

        public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
        {
            return [...$relationManagers, PipelineBridgeRelationManager::class];
        }

        public function mutateDataBeforeCreate(array $data): array
        {
            return $data;
        }

        public function afterCreate(Model $record): void {}

        public function mutateDataBeforeSave(Model $record, array $data): array
        {
            return $data;
        }

        public function afterSave(Model $record): void {}

        public function columns(): array
        {
            return [];
        }

        public function filters(): array
        {
            return [];
        }

        public function recordActions(): array
        {
            return [];
        }

        public function toolbarActions(): array
        {
            return [];
        }
    };
}

/**
 * @param  array<int, Component>  $components
 * @return array<int, string>
 */
function capellPipelineComponentNames(array $components): array
{
    return array_map(
        static fn (Component $component): string => $component instanceof TextInput ? $component->getName() : '',
        $components,
    );
}

/**
 * @param  array<int, Component>  $components
 * @return array<int, string>
 */
function capellPipelineTextContents(array $components): array
{
    return array_map(
        static fn (Component $component): string => $component instanceof Text ? filamentText($component->getContent()) : '',
        $components,
    );
}

/**
 * @param  array<int, Action>  $actions
 * @return array<int, string>
 */
function capellPipelineActionNames(array $actions): array
{
    return array_map(
        static fn (Action $action): string => (string) $action->getName(),
        $actions,
    );
}

/**
 * @return class-string<RelationManager>
 */
function capellPipelineRelationManager(string $prefix, string $suffix): string
{
    return match ($prefix . ':' . $suffix) {
        'page:a' => PipelinePageRelationManagerA::class,
        'page:b' => PipelinePageRelationManagerB::class,
        'site:a' => PipelineSiteRelationManagerA::class,
        'user:legacy' => PipelineUserRelationManagerLegacy::class,
        default => PipelineExistingRelationManager::class,
    };
}

it('centralises page schema extension hooks', function (): void {
    app()->bind('pipeline.page.a', fn (): PageSchemaExtender => capellPipelinePageExtender('a'));
    app()->bind('pipeline.page.b', fn (): PageSchemaExtender => capellPipelinePageExtender('b'));
    app()->tag(['pipeline.page.a', 'pipeline.page.b'], PageSchemaExtender::TAG);

    $pipeline = new AdminSchemaExtensionPipeline;
    $schema = Mockery::mock(Schema::class);
    $record = Mockery::mock(Model::class);

    expect($pipeline->pageRelationManagers($record, [PipelineExistingRelationManager::class]))->toBe([
        PipelineExistingRelationManager::class,
        PipelinePageRelationManagerA::class,
        PipelinePageRelationManagerB::class,
    ])
        ->and($pipeline->pageTabs($schema, []))->toBe(['page-tab-a', 'page-tab-b'])
        ->and(capellPipelineComponentNames($pipeline->pageTranslationComponentsForHook($schema, PageTranslationSchemaHookEnum::AfterTitle)))
        ->toBe(['page_after-title_a', 'page_after-title_b'])
        ->and(capellPipelineTextContents($pipeline->pageSidebarComponents($schema)))->toBe(['page-sidebar-a', 'page-sidebar-b']);
});

it('centralises layout and site schema extension hooks', function (): void {
    app()->bind('pipeline.layout.a', fn (): LayoutSchemaExtender => new readonly class implements LayoutSchemaExtender
    {
        public function extendRelationManagers(Model $record, array $relationManagers): array
        {
            return [...$relationManagers, 'layout-relation'];
        }

        public function extendTabs(Schema $schema, array $tabs): array
        {
            return [...$tabs, 'layout-tab'];
        }
    });

    app()->bind('pipeline.site.a', fn (): SiteSchemaExtender => capellPipelineSiteExtender('a'));
    app()->tag(['pipeline.layout.a'], LayoutSchemaExtender::TAG);
    app()->tag(['pipeline.site.a'], SiteSchemaExtender::TAG);

    $pipeline = new AdminSchemaExtensionPipeline;
    $schema = Mockery::mock(Schema::class);
    $record = Mockery::mock(Model::class);

    expect($pipeline->layoutRelationManagers($record, []))->toBe(['layout-relation'])
        ->and($pipeline->layoutTabs($schema, []))->toBe(['layout-tab'])
        ->and($pipeline->siteRelationManagers($record, [PipelineExistingRelationManager::class]))->toBe([
            PipelineExistingRelationManager::class,
            PipelineSiteRelationManagerA::class,
        ])
        ->and($pipeline->siteTabs($schema, []))->toBe(['site-tab-a'])
        ->and(capellPipelineComponentNames($pipeline->siteMetaDetailsComponents($schema, [])))->toBe(['site_meta_a'])
        ->and(capellPipelineComponentNames($pipeline->siteCreateWizardComponentsForHook($schema, SiteCreateWizardHookEnum::PagesStepEnd)))
        ->toBe(['site_wizard_pages-step-end_a']);
});

it('centralises action extension hooks with support filtering and dedupe', function (): void {
    $pageHeaderExtender = new readonly class implements PageHeaderActionExtender
    {
        public function actions(): array
        {
            return [Action::make('page-header')];
        }
    };

    app()->instance('pipeline.action.page-header', $pageHeaderExtender);
    app()->bind('pipeline.action.preview', fn (): PagePreviewActionExtender => new readonly class implements PagePreviewActionExtender
    {
        public function actions(): array
        {
            return [Action::make('page-preview')];
        }
    });
    app()->bind('pipeline.action.title', fn (): PageTitleWithSlugInputExtender => new readonly class implements PageTitleWithSlugInputExtender
    {
        public function actions(): array
        {
            return [Action::make('page-title')];
        }

        public function afterLabel(FusedGroup $component): ?Schema
        {
            return null;
        }
    });
    app()->bind('pipeline.action.site-header', fn (): SiteHeaderActionExtender => new readonly class implements SiteHeaderActionExtender
    {
        public function actions(): array
        {
            return [Action::make('site-header')];
        }
    });
    app()->bind('pipeline.action.resource-supported', fn (): ResourceHeaderActionExtender => new readonly class implements ResourceHeaderActionExtender
    {
        public function supports(string $pageClass): bool
        {
            return $pageClass === PipelineResourcePage::class;
        }

        public function actions(): array
        {
            return [Action::make('resource-supported')];
        }
    });
    app()->bind('pipeline.action.resource-skipped', fn (): ResourceHeaderActionExtender => new readonly class implements ResourceHeaderActionExtender
    {
        public function supports(string $pageClass): bool
        {
            return false;
        }

        public function actions(): array
        {
            return [Action::make('resource-skipped')];
        }
    });

    app()->tag(['pipeline.action.page-header', 'pipeline.action.page-header'], PageHeaderActionExtender::TAG);
    app()->tag(['pipeline.action.preview'], PagePreviewActionExtender::TAG);
    app()->tag(['pipeline.action.title'], PageTitleWithSlugInputExtender::TAG);
    app()->tag(['pipeline.action.site-header'], SiteHeaderActionExtender::TAG);
    app()->tag(['pipeline.action.resource-supported', 'pipeline.action.resource-skipped'], ResourceHeaderActionExtender::TAG);

    $pipeline = new AdminSchemaExtensionPipeline;

    expect(capellPipelineActionNames($pipeline->pageHeaderActions()))->toBe(['page-header'])
        ->and(capellPipelineActionNames($pipeline->pagePreviewActions()))->toBe(['page-preview'])
        ->and(capellPipelineActionNames($pipeline->pageTitleActions()))->toBe(['page-title'])
        ->and(capellPipelineActionNames($pipeline->siteHeaderActions()))->toBe(['site-header'])
        ->and(capellPipelineActionNames($pipeline->resourceHeaderActions(PipelineResourcePage::class)))->toBe(['resource-supported']);
});

it('merges supported user schema extenders and user bridges', function (): void {
    app()->bind('pipeline.user.skipped', fn (): UserSchemaExtender => capellPipelineUserExtender('skipped', supports: false));
    app()->bind('pipeline.user.legacy', fn (): UserSchemaExtender => capellPipelineUserExtender('legacy'));
    app()->bind('pipeline.user.bridge', fn (): UserResourceBridge => capellPipelineUserBridge('bridge'));
    app()->tag(['pipeline.user.skipped', 'pipeline.user.legacy'], UserSchemaExtender::TAG);
    app()->tag(['pipeline.user.bridge'], UserResourceBridge::TAG);

    $pipeline = new AdminSchemaExtensionPipeline;
    $schema = Mockery::mock(Schema::class);
    $record = Mockery::mock(Model::class);
    $context = UserSchemaContextData::forCreate(schemaType: 'editor');

    expect(capellPipelineComponentNames($pipeline->userComponentsForHook($schema, UserSchemaHookEnum::AfterIdentity, $context)))
        ->toBe(['user_after_identity_legacy', 'bridge_after_identity_bridge'])
        ->and(capellPipelineComponentNames($pipeline->userSidebarComponents($schema, $context)))
        ->toBe(['user_sidebar_legacy', 'bridge_sidebar_bridge'])
        ->and($pipeline->userRelationManagers($record, [PipelineExistingRelationManager::class], $context))->toBe([
            PipelineExistingRelationManager::class,
            PipelineUserRelationManagerLegacy::class,
            PipelineBridgeRelationManager::class,
        ]);
});
