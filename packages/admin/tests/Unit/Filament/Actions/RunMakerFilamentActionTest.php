<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\Makers\RunMakerFilamentAction;
use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerFileData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Data\Makers\MakerSafetyData;
use Capell\Core\Support\Makers\MakerSafety;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

it('builds a maker action with the expected form and readonly defaults', function (): void {
    config(['capell.diagnostics.readonly_preview' => false]);

    bindMakerActionDependencies(supportsPhpWrites: false, phpWritesAllowed: false);

    $action = RunMakerFilamentAction::make('admin.configurator');
    $schema = expectPresent($action->getSchema(Schema::make())?->getComponents());
    $nameInput = expectPresent($schema[0] ?? null);
    $dryRunCheckbox = expectPresent($schema[1] ?? null);
    $forceCheckbox = expectPresent($schema[2] ?? null);

    expect(filamentObjectName($action))->toBe('maker_admin_configurator')
        ->and($action->getLabel())->toBe('Admin configurator')
        ->and($action->isVisible())->toBeTrue()
        ->and($schema)->toHaveCount(3)
        ->and($nameInput)->toBeInstanceOf(TextInput::class)
        ->and(filamentObjectName($nameInput))->toBe('name')
        ->and($dryRunCheckbox)->toBeInstanceOf(Checkbox::class)
        ->and(filamentObjectName($dryRunCheckbox))->toBe('dryRun')
        ->and(filamentObjectDefaultState($dryRunCheckbox))->toBeFalse()
        ->and($forceCheckbox)->toBeInstanceOf(Checkbox::class)
        ->and(filamentObjectName($forceCheckbox))->toBe('force')
        ->and(filamentObjectDefaultState($forceCheckbox))->toBeFalse();
});

it('hides php-writing maker actions when the environment forbids php writes', function (): void {
    bindMakerActionDependencies(supportsPhpWrites: true, phpWritesAllowed: false);

    expect(RunMakerFilamentAction::make('admin.configurator')->isVisible())->toBeFalse();

    bindMakerActionDependencies(supportsPhpWrites: true, phpWritesAllowed: true);

    expect(RunMakerFilamentAction::make('admin.configurator')->isVisible())->toBeTrue();
});

it('runs maker actions with submitted form data and reports generated files', function (): void {
    bindMakerActionDependencies(supportsPhpWrites: false, phpWritesAllowed: true);

    $spy = bindFakeAction(RunMakerAction::class, new MakerResultData(
        maker: 'admin.configurator',
        files: collect([
            new MakerFileData('app/Filament/Configurators/PageConfigurator.php', 'create', false, true),
        ]),
        databaseRecords: collect(),
        commands: collect(),
        notes: collect(),
        successful: false,
    ));

    RunMakerFilamentAction::make('admin.configurator')->call([
        'data' => [
            'name' => 'Article Page',
            'dryRun' => false,
            'force' => true,
        ],
    ]);

    $input = $spy->args[0] ?? null;

    expect($spy->called)->toBeTrue()
        ->and($input)->toBeInstanceOf(MakerInputData::class)
        ->and($input->maker)->toBe('admin.configurator')
        ->and($input->values)->toBe(['name' => 'Article Page'])
        ->and($input->dryRun)->toBeFalse()
        ->and($input->force)->toBeTrue()
        ->and($input->databaseWrites)->toBeFalse();
});

it('rethrows maker failures after notifying the operator', function (): void {
    bindMakerActionDependencies(supportsPhpWrites: false, phpWritesAllowed: true);

    app()->bind(RunMakerAction::class, fn (): object => new class
    {
        public function handle(MakerInputData $input): MakerResultData
        {
            throw new RuntimeException('Maker failed hard.');
        }
    });

    expect(fn (): mixed => RunMakerFilamentAction::make('admin.configurator')->call([
        'data' => [
            'name' => 'Article Page',
            'dryRun' => true,
            'force' => false,
        ],
    ]))->toThrow(RuntimeException::class, 'Maker failed hard.');
});

function bindMakerActionDependencies(bool $supportsPhpWrites, bool $phpWritesAllowed): void
{
    $definition = new MakerDefinitionData(
        key: 'admin.configurator',
        label: 'Admin configurator',
        description: 'Create an admin configurator.',
        group: 'admin',
        icon: 'heroicon-o-wrench',
        supportsDatabaseWrites: false,
        supportsPhpWrites: $supportsPhpWrites,
    );

    app()->instance(MakerRegistryInterface::class, new readonly class($definition) implements MakerRegistryInterface
    {
        public function __construct(private MakerDefinitionData $definition) {}

        public function register(Maker $maker): void {}

        public function has(string $key): bool
        {
            return $key === $this->definition->key;
        }

        public function get(string $key): Maker
        {
            return new readonly class($this->definition) implements Maker
            {
                public function __construct(private MakerDefinitionData $definition) {}

                public function definition(): MakerDefinitionData
                {
                    return $this->definition;
                }

                public function preview(MakerInputData $input): MakerPreviewData
                {
                    return new MakerPreviewData($input->maker, collect(), collect(), collect(), collect());
                }

                public function run(MakerInputData $input): MakerResultData
                {
                    return new MakerResultData($input->maker, collect(), collect(), collect(), collect(), true);
                }
            };
        }

        public function all(): Collection
        {
            return collect([$this->get($this->definition->key)]);
        }
    });

    app()->instance(MakerSafety::class, new class($phpWritesAllowed) extends MakerSafety
    {
        public function __construct(private readonly bool $phpWritesAllowed) {}

        public function current(): MakerSafetyData
        {
            return new MakerSafetyData(
                phpWritesAllowed: $this->phpWritesAllowed,
                databaseWritesAllowed: false,
                allowedRoots: collect(),
                environment: 'testing',
                messages: collect(),
            );
        }
    });
}
