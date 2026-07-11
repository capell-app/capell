<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Configurators;

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Filament\Components\Forms\FixedWidthSidebar;
use Capell\Admin\Filament\Components\Forms\ImageSourcePicker;
use Capell\Admin\Filament\Components\Forms\Page\ParentSelect;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\LandingPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\ResultsPageConfigurator;
use Capell\Admin\Testing\Filament\ReadsRawSchemaComponents;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Feature\Filament\Configurators\Fixtures\PageConfiguratorTestLivewire;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use UnhandledMatchError;

final class DefaultPageConfiguratorTest extends AdminTestCase
{
    public function test_default_page_configurator_has_no_tabs_without_extenders(): void
    {
        $schema = Schema::make();
        $schema = $schema->model(Page::class);

        $configuratorClass = new DefaultPageConfigurator;
        $tabs = $this->callGetTabs($configuratorClass, $schema);

        $this->assertCount(0, $tabs, 'DefaultPageConfigurator should not add tabs without tagged extenders');
    }

    public function test_results_page_configurator_has_no_tabs_without_extenders(): void
    {
        $schema = Schema::make();
        $schema = $schema->model(Page::class);

        $configuratorClass = new ResultsPageConfigurator;
        $tabs = $this->callGetTabs($configuratorClass, $schema);

        $this->assertCount(0, $tabs, 'ResultsPageConfigurator should not add tabs without tagged extenders');
    }

    public function test_page_configurator_extender_can_contribute_navigation_tab(): void
    {
        $schema = Schema::make();
        $schema = $schema->model(Page::class);

        $fakeExtender = new class implements PageSchemaExtender
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
                $tabs[] = Tab::make('navigation')->label('Navigation');

                return $tabs;
            }

            public function extendSidebarComponents(Schema $schema): array
            {
                return [];
            }
        };

        $fakeExtenderClass = $fakeExtender::class;
        app()->instance($fakeExtenderClass, $fakeExtender);
        app()->tag([$fakeExtenderClass], PageSchemaExtender::TAG);

        $configuratorClass = new DefaultPageConfigurator;
        $tabs = $this->callGetTabs($configuratorClass, $schema);

        $this->assertCount(1, $tabs, 'DefaultPageConfigurator should include extender-contributed tabs');
    }

    public function test_default_page_edit_layout_keeps_sidebar_focused_on_page_context(): void
    {
        Blueprint::factory()->page()->default()->createOne(['key' => 'standard']);

        $configurator = new DefaultPageConfigurator;
        $schema = Schema::make(new PageConfiguratorTestLivewire)
            ->operation('edit')
            ->model(Page::class);

        $components = $configurator->make($schema);
        $layout = $components[0] ?? null;

        $this->assertInstanceOf(FixedWidthSidebar::class, $layout);

        $mainHeadings = $this->schemaComponentHeadings($layout->getMainSchema());
        $sidebar = $layout->getSidebarSchema();
        $sidebarHeadings = $this->schemaComponentHeadings($sidebar);

        $this->assertContains(__('capell-admin::form.hero_settings'), $mainHeadings);
        $this->assertContains(__('capell-admin::generic.page_configuration'), $mainHeadings);
        $this->assertContains(__('capell-admin::generic.page_context'), $sidebarHeadings);
        $this->assertNotContains(__('capell-admin::form.hero_settings'), $sidebarHeadings);
        $this->assertNotContains(__('capell-admin::generic.page_configuration'), $sidebarHeadings);

        // The WordPress-style publish panel is pinned to the very top of the sidebar.
        $this->assertInstanceOf(Livewire::class, $sidebar[0] ?? null);

        $contextSection = $sidebar[1] ?? null;

        $this->assertInstanceOf(Section::class, $contextSection);

        $contextComponents = ReadsRawSchemaComponents::childComponents($contextSection);

        $this->assertCount(2, $contextComponents);
        $this->assertInstanceOf(ParentSelect::class, $contextComponents[0]);
        $this->assertInstanceOf(ImageSourcePicker::class, $contextComponents[1]);
    }

    public function test_default_page_configurator_builds_form_schemas_for_primary_operations(): void
    {
        Blueprint::factory()->page()->default()->createOne(['key' => 'standard']);
        $configurator = new DefaultPageConfigurator;

        foreach (['create', 'createOption', 'replicate', 'edit', 'editOption'] as $operation) {
            $schema = Schema::make(new PageConfiguratorTestLivewire)
                ->operation($operation)
                ->model(Page::class);

            $components = $configurator->make($schema);

            $this->assertNotEmpty($components, sprintf('Expected components for [%s].', $operation));
        }

        $this->expectException(UnhandledMatchError::class);

        $configurator->make(Schema::make(new PageConfiguratorTestLivewire)->operation('view')->model(Page::class));
    }

    public function test_default_page_configurator_uses_focused_system_page_schema_for_system_records(): void
    {
        $type = Blueprint::factory()->page()->createOne([
            'key' => PageTypeEnum::Maintenance->value,
        ]);
        $page = Page::factory()->type($type)->createOne();
        $page->setRelation('type', $type);

        $configurator = new DefaultPageConfigurator;
        $schema = Schema::make(new PageConfiguratorTestLivewire)
            ->operation('edit')
            ->record($page);

        $components = $configurator->make($schema);

        $this->assertNotEmpty($components);
        $this->assertCount(0, array_filter($components, fn (mixed $component): bool => $component instanceof Tabs));

        $layout = $components[0] ?? null;
        $this->assertInstanceOf(FixedWidthSidebar::class, $layout);

        $sidebarHeadings = $this->schemaComponentHeadings($layout->getSidebarSchema());
        $mainHeadings = $this->schemaComponentHeadings($layout->getMainSchema());

        $this->assertNotContains(__('capell-admin::form.hero_settings'), $sidebarHeadings);
        $this->assertNotContains(__('capell-admin::form.hero_settings'), $mainHeadings);
    }

    public function test_landing_page_configurator_builds_create_edit_and_nested_option_schemas(): void
    {
        Blueprint::factory()->page()->default()->createOne(['key' => 'standard']);
        $configurator = new LandingPageConfigurator;

        foreach (['create', 'createOption', 'replicate', 'edit', 'editOption'] as $operation) {
            $schema = Schema::make(new PageConfiguratorTestLivewire)
                ->operation($operation)
                ->model(Page::class);

            $components = $configurator->make($schema);

            $this->assertNotEmpty($components, sprintf('Expected landing page components for [%s].', $operation));
        }

        $this->expectException(UnhandledMatchError::class);

        $configurator->make(Schema::make(new PageConfiguratorTestLivewire)->operation('view')->model(Page::class));
    }

    /**
     * @return array<int, Tab>
     */
    private function callGetTabs(DefaultPageConfigurator|ResultsPageConfigurator $configuratorClass, Schema $schema): array
    {
        $reflectionMethod = new ReflectionMethod($configuratorClass::class, 'getTabs');

        return $reflectionMethod->invoke($configuratorClass, $schema);
    }

    /**
     * @param  array<int, mixed>  $components
     * @return array<int, string>
     */
    private function schemaComponentHeadings(array $components): array
    {
        return collect($components)
            ->map(fn (mixed $component): string => is_object($component) && method_exists($component, 'getHeading')
                ? filamentText($component->getHeading())
                : '')
            ->filter()
            ->values()
            ->all();
    }
}
