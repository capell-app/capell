<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\ConfiguratorSelect;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Support\Makers\ConfiguratorSourceResolver;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Data\Makers\MakerFileData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Data\Makers\MakerSafetyData;
use Capell\Core\Support\Makers\MakerSafety;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $registerConfigurators = new ReflectionMethod(CapellAdminPlugin::class, 'registerConfigurators');
    $registerConfigurators->invoke(CapellAdminPlugin::make());

    foreach ([ConfiguratorTypeEnum::Page, ConfiguratorTypeEnum::Blueprint] as $type) {
        foreach ($type->getConfigurators() as $configuratorClass) {
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::configurator(
                class: $configuratorClass,
                group: $type->value,
                name: $configuratorClass::getKey(),
            ));
        }
    }
});

it('builds generated configurator keys that remove the configurator type suffix', function (): void {
    expect(ConfiguratorSelect::generatedConfiguratorKey('Pages', 'Landing Page'))->toBe('Landing')
        ->and(ConfiguratorSelect::generatedConfiguratorKey('Blueprints', 'Custom Blueprint Configurator'))->toBe('Custom');
});

it('suggests create configurator names from the current record name', function (): void {
    $component = mountedConfiguratorSelect(
        ConfiguratorSelect::make('configurator')
            ->setupOptions(ConfiguratorTypeEnum::Page)
            ->withCreateConfiguratorAction(ConfiguratorTypeEnum::Page),
        ['name' => 'Hero Slider'],
    );

    expect($component->defaultCreationName('Configurator'))->toBe('HeroSliderConfigurator');
});

it('builds html configurator options with fallback state and helper hint metadata', function (): void {
    $component = mountedConfiguratorSelect(
        ConfiguratorSelect::make('configurator')->setupOptions(ConfiguratorTypeEnum::Page),
        ['configurator' => 'legacy'],
    );

    $options = $component->getOptions();

    expect($options)->toHaveKeys(['legacy', 'Default', 'Landing', 'Results'])
        ->and($options['Default'])->toContain('DefaultPageConfigurator')
        ->and($component->getHintIcon())->toBe('heroicon-m-information-circle');
});

it('accepts configurator type callbacks when resolving options', function (): void {
    $component = mountedConfiguratorSelect(
        ConfiguratorSelect::make('type_configurator')
            ->setupOptions(fn (): string => ConfiguratorTypeEnum::Blueprint->value),
    );

    expect($component->getOptions())->toHaveKeys(['Default', 'page']);
});

it('creates configurators from the select suffix action and selects the generated key', function (): void {
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

    $makerSpy = bindFakeAction(RunMakerAction::class, new MakerResultData(
        maker: 'admin.configurator',
        files: new Collection([
            new MakerFileData(
                path: 'app/Filament/Configurators/Pages/CampaignConfigurator.php',
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

    $component = mountedConfiguratorSelect(
        ConfiguratorSelect::make('configurator')
            ->setupOptions(ConfiguratorTypeEnum::Page)
            ->withCreateConfiguratorAction(ConfiguratorTypeEnum::Page),
    );

    $action = expectPresent($component->getSuffixActions()['createConfigurator'] ?? null);
    $schema = expectPresent($action->getSchema(Schema::make())?->getComponents());

    expect($action->isVisible())->toBeTrue()
        ->and($schema)->toHaveCount(2)
        ->and(filamentObjectName($schema[0]))->toBe('name')
        ->and(filamentObjectName($schema[1]))->toBe('source');

    assert($schema[1] instanceof Select);

    expect($schema[1]->getOptions())->toHaveKeys([ConfiguratorSourceResolver::BLANK_SOURCE_KEY, 'Default'])
        ->and(filamentObjectDefaultState($schema[1]))->toBe(ConfiguratorSourceResolver::BLANK_SOURCE_KEY);

    $action->call(['data' => [
        'name' => 'Campaign Page',
        'source' => ConfiguratorSourceResolver::BLANK_SOURCE_KEY,
    ]]);

    $input = $makerSpy->args[0] ?? null;

    expect($makerSpy->called)->toBeTrue()
        ->and($input?->maker)->toBe('admin.configurator')
        ->and($input?->values)->toMatchArray([
            'type' => ConfiguratorTypeEnum::Page->value,
            'name' => 'Campaign Page',
            'source' => ConfiguratorSourceResolver::BLANK_SOURCE_KEY,
        ])
        ->and($component->getState())->toBe('Campaign');
});

/**
 * @param  array<string, mixed>  $state
 */
function mountedConfiguratorSelect(ConfiguratorSelect $component, array $state = []): ConfiguratorSelect
{
    $schema = Schema::make(Livewire::make()->data($state))
        ->statePath('data')
        ->components([$component]);

    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof ConfiguratorSelect);
    $mounted->state(data_get($state, $mounted->getName()));

    return $mounted;
}
