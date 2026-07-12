<?php

declare(strict_types=1);

use Capell\Admin\Contracts\RegistryInspectorInterface;
use Capell\Admin\Data\Diagnostics\RegistrySourceData;
use Capell\Admin\Filament\Components\Forms\ComponentSelect;
use Capell\Admin\Support\Makers\AdminBladeComponentMaker;
use Capell\Admin\Support\Makers\ComponentSourceResolver;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Data\Makers\MakerFileData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Data\Makers\MakerSafetyData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Support\Makers\MakerRegistry;
use Capell\Core\Support\Makers\MakerSafety;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

it('lists registered components keeps missing selections visible and exposes source diagnostics', function (): void {
    CapellCore::registerComponent('coverage-page', 'Hero Block', 'views/components/hero.blade.php');
    CapellCore::registerComponent('coverage-page', 'Feature Grid', 'views/components/features.blade.php');

    app()->instance(RegistryInspectorInterface::class, new class implements RegistryInspectorInterface
    {
        public function configurators(?string $configuratorType = null): Collection
        {
            return collect();
        }

        public function components(?string $componentType = null): Collection
        {
            /** @var Collection<int|string, mixed> $components */
            $components = collect([
                new RegistrySourceData(
                    key: 'views/components/hero.blade.php',
                    label: 'Hero Block',
                    kind: 'component',
                    class: null,
                    view: 'components.hero',
                    path: '/packages/blog/resources/views/components/hero.blade.php',
                    sourcePackage: 'capell-app/blog',
                    sourceMode: 'manual',
                    cachePath: null,
                    statePath: null,
                    flow: collect(),
                ),
            ]);

            return $components;
        }

        public function blocks(): Collection
        {
            return collect();
        }

        public function widgets(): Collection
        {
            return collect();
        }
    });

    $component = Schema::make(Livewire::make()->data(['component' => 'legacy-component']))
        ->statePath('data')
        ->components([
            ComponentSelect::make('component')
                ->setupType(fn (): string => 'coverage-page')
                ->withCreateComponentAction()
                ->withSourceFlow(),
        ])
        ->getComponents()[0];
    assert($component instanceof ComponentSelect);
    $component->state('legacy-component');

    $options = $component->getOptions();
    $suffixAction = collect($component->getSuffixActions())->first();

    expect($options)->toHaveKey('legacy-component')
        ->and($options['legacy-component'])->toBe('legacy-component')
        ->and($options['views/components/features.blade.php'])->toContain('Feature Grid')
        ->and($options['views/components/hero.blade.php'])->toContain('Hero Block')
        ->and($component->getHintIcon())->toBe('heroicon-m-information-circle')
        ->and($component->getHintIconTooltip())->toBe('legacy-component')
        ->and($suffixAction)->toBeInstanceOf(Action::class)
        ->and($suffixAction?->getLabel())->toBe(__('capell-admin::generic.create_component'))
        ->and($suffixAction?->isDisabled())->toBeFalse();

    $component->state('views/components/hero.blade.php');

    expect($component->getHintIcon())->toBe('heroicon-m-information-circle')
        ->and($component->getHintIconTooltip())->toBe('/packages/blog/resources/views/components/hero.blade.php');
});

it('shows inherited component hints from a related blueprint when no component is selected', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne([
        'component' => 'views/components/default-page.blade.php',
        'meta' => [
            'hero_component' => 'views/components/hero.blade.php',
            'meta.hero_component' => 'views/components/hero.blade.php',
        ],
    ]);
    $page = Page::factory()->type($blueprint)->createOne();
    $page->setRelation('blueprint', $blueprint);

    $component = Schema::make(Livewire::make()->data([
        'component' => null,
        'meta' => [
            'hero_component' => null,
        ],
    ]))
        ->statePath('data')
        ->record($page)
        ->components([
            ComponentSelect::make('component')->setupType('coverage-page'),
            ComponentSelect::make('meta.hero_component')->setupType('coverage-page'),
        ])
        ->getComponents();

    $baseComponent = $component[0];
    $metaComponent = $component[1];
    assert($baseComponent instanceof ComponentSelect);
    assert($metaComponent instanceof ComponentSelect);

    expect($baseComponent->getHintIcon())->toBe('heroicon-m-information-circle')
        ->and($baseComponent->getHintIconTooltip())->toBe(__('capell-admin::generic.inherited_type_info', [
            'component' => 'views/components/default-page.blade.php',
        ]))
        ->and($metaComponent->getHintIcon())->toBe('heroicon-m-information-circle')
        ->and($metaComponent->getHintIconTooltip())->toBe(__('capell-admin::generic.inherited_type_info', [
            'component' => 'views/components/hero.blade.php',
        ]));
});

it('creates components from the select suffix action and selects the generated key', function (): void {
    app()->instance(MakerSafety::class, new class extends MakerSafety
    {
        public function current(): MakerSafetyData
        {
            return new MakerSafetyData(
                phpWritesAllowed: true,
                databaseWritesAllowed: false,
                allowedRoots: collect(),
                environment: 'testing',
                messages: collect(),
            );
        }
    });

    app()->instance(RegistryInspectorInterface::class, new class implements RegistryInspectorInterface
    {
        public function configurators(?string $configuratorType = null): Collection
        {
            return collect();
        }

        public function components(?string $componentType = null): Collection
        {
            /** @var Collection<int|string, mixed> $components */
            $components = collect([
                new RegistrySourceData(
                    key: 'capell.widget.hero',
                    label: 'Hero',
                    kind: 'component',
                    class: null,
                    view: 'capell.widget.hero',
                    path: '/packages/layout/resources/views/components/widget/hero.blade.php',
                    sourcePackage: 'package',
                    sourceMode: 'registered',
                    cachePath: null,
                    statePath: $componentType,
                    flow: collect(),
                ),
            ]);

            return $components;
        }

        public function blocks(): Collection
        {
            return collect();
        }

        public function widgets(): Collection
        {
            return collect();
        }
    });

    $makerSpy = bindFakeAction(RunMakerAction::class, new MakerResultData(
        maker: 'admin.component',
        files: new Collection([
            new MakerFileData(
                path: 'resources/views/components/widget/hero-card.blade.php',
                operation: 'create',
                exists: false,
                writable: true,
            ),
        ]),
        databaseRecords: new Collection,
        commands: new Collection,
        notes: new Collection,
        successful: true,
    ));

    $component = mountedUnitComponentSelect(
        ComponentSelect::make('component')
            ->setupType('Widget')
            ->withCreateComponentAction(),
        ['name' => 'Hero Card'],
    );

    $action = expectPresent($component->getSuffixActions()['createComponent'] ?? null);
    $schema = expectPresent($action->getSchema(Schema::make())?->getComponents());

    expect($action->isVisible())->toBeTrue()
        ->and($component->defaultCreationName('Component'))->toBe('HeroCardComponent')
        ->and($schema)->toHaveCount(2)
        ->and(filamentObjectName($schema[0]))->toBe('name')
        ->and(filamentObjectName($schema[1]))->toBe('source');

    assert($schema[1] instanceof Select);

    expect($schema[1]->getOptions())->toHaveKeys([ComponentSourceResolver::BLANK_SOURCE_KEY, 'capell.widget.hero'])
        ->and(filamentObjectDefaultState($schema[1]))->toBe(ComponentSourceResolver::BLANK_SOURCE_KEY);

    $action->call(['data' => [
        'name' => 'Hero Card',
        'source' => ComponentSourceResolver::BLANK_SOURCE_KEY,
    ]]);

    $input = $makerSpy->args[0] ?? null;

    expect($makerSpy->called)->toBeTrue()
        ->and($input?->maker)->toBe('admin.component')
        ->and($input?->values)->toMatchArray([
            'type' => 'Widget',
            'name' => 'Hero Card',
            'source' => ComponentSourceResolver::BLANK_SOURCE_KEY,
        ])
        ->and($component->getState())->toBe('widget.hero-card');
});

it('runs the component create action through the maker and mocked filesystem', function (): void {
    app()->instance(MakerSafety::class, new class extends MakerSafety
    {
        public function current(): MakerSafetyData
        {
            return new MakerSafetyData(
                phpWritesAllowed: true,
                databaseWritesAllowed: false,
                allowedRoots: collect([resource_path('views/components')]),
                environment: 'testing',
                messages: collect(),
            );
        }

        public function pathIsAllowed(string $path): bool
        {
            return str_starts_with($path, resource_path('views/components') . DIRECTORY_SEPARATOR);
        }
    });

    $registry = new MakerRegistry;
    $registry->register(resolve(AdminBladeComponentMaker::class));

    app()->instance(MakerRegistryInterface::class, $registry);

    $targetPath = resource_path('views/components/widget/hero-card.blade.php');
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->twice()->with($targetPath)->andReturnFalse();
    $filesystem->shouldReceive('ensureDirectoryExists')->once()->with(dirname($targetPath))->andReturnNull();
    $filesystem->shouldReceive('put')
        ->once()
        ->with($targetPath, Mockery::on(fn (string $contents): bool => str_contains($contents, '<section>')))
        ->andReturn(32);

    app()->instance(Filesystem::class, $filesystem);

    $component = mountedUnitComponentSelect(
        ComponentSelect::make('component')
            ->setupType('Widget')
            ->withCreateComponentAction(),
        ['name' => 'Hero Card'],
    );

    $action = expectPresent($component->getSuffixActions()['createComponent'] ?? null);
    $action->call(['data' => [
        'name' => 'Hero Card',
        'source' => ComponentSourceResolver::BLANK_SOURCE_KEY,
    ]]);

    expect($component->getState())->toBe('widget.hero-card');
});

/**
 * @param  array<string, mixed>  $state
 */
function mountedUnitComponentSelect(ComponentSelect $component, array $state = []): ComponentSelect
{
    $schema = Schema::make(Livewire::make()->data($state))
        ->statePath('data')
        ->components([$component]);

    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof ComponentSelect);
    $mounted->state(data_get($state, $mounted->getName()));

    return $mounted;
}
