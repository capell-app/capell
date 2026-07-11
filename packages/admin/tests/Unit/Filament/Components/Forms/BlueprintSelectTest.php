<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\BlueprintSelect as BaseBlueprintSelect;
use Capell\Admin\Filament\Components\Forms\Site\BlueprintSelect as SiteBlueprintSelect;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures\StringTypedBlueprintSelectForTest;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('resolves the default blueprint for the current subject through admin form state', function (): void {
    Blueprint::factory()->site()->default()->create(['key' => 'default-site', 'name' => 'Default site']);
    $selectedBlueprint = Blueprint::factory()->site()->create(['key' => 'client-site', 'name' => 'Client site']);
    Blueprint::factory()->page()->default()->create(['key' => 'page-type', 'name' => 'Page type']);

    $component = mountedBlueprintSelect(
        SiteBlueprintSelect::make('blueprint_id')
            ->modifySelectOptionsQueryUsing(
                fn (Builder $query): Builder => $query->where('key', 'client-site'),
            ),
    );

    expect($component->getBlueprint())->toBe(BlueprintSubjectEnum::Site->value)
        ->and($component->getDefaultState())->toBe($selectedBlueprint->getKey())
        ->and(StringTypedBlueprintSelectForTest::make('custom_blueprint')->getBlueprint())->toBe('custom');
});

it('loads relationship options for enabled blueprints in the selected subject', function (): void {
    $enabledBlueprint = Blueprint::factory()->site()->create(['name' => 'Enabled site type']);
    $disabledBlueprint = Blueprint::factory()->site()->create(['name' => 'Disabled site type', 'status' => false]);
    Blueprint::factory()->page()->create(['name' => 'Page type']);

    $component = mountedBlueprintSelect(
        SiteBlueprintSelect::make('blueprint_id')->withRelation(),
        model: Site::class,
    );

    expect($component->getOptionsFromRelationship())
        ->toHaveKey($enabledBlueprint->getKey())
        ->not->toHaveKey($disabledBlueprint->getKey())
        ->not->toContain('Page type');
});

it('configures nested create and edit actions for blueprint management', function (): void {
    $blueprint = Blueprint::factory()->site()->create([
        'name' => 'Client type',
        'key' => 'client-type',
    ]);

    $component = mountedBlueprintSelect(
        SiteBlueprintSelect::make('blueprint_id')
            ->withRelation()
            ->withCreateForm()
            ->withEditForm(),
        ['blueprint_id' => $blueprint->getKey()],
        Site::class,
    );

    $createCallbackWasCalled = false;
    $editCallbackWasCalled = false;

    $component->afterCreateOptionActionCreated(function () use (&$createCallbackWasCalled): void {
        $createCallbackWasCalled = true;
    });
    $component->afterEditOptionActionUpdated(function () use (&$editCallbackWasCalled): void {
        $editCallbackWasCalled = true;
    });

    $createAction = configuredBlueprintSelectAction($component, 'modifyCreateOptionActionUsing', 'createOption');
    $editAction = configuredBlueprintSelectAction($component, 'modifyEditOptionActionUsing', 'editOption');

    expect($component->getOptionLabelFromRecord($blueprint))->toContain('Client type')
        ->and($component->getCreateOptionActionForm(Schema::make(Livewire::make())))->toBeInstanceOf(Schema::class)
        ->and($component->getEditOptionActionForm(Schema::make(Livewire::make())))->toBeInstanceOf(Schema::class)
        ->and($component->getEditOptionActionFormData())->toMatchArray([
            'id' => $blueprint->getKey(),
            'name' => 'Client type',
            'key' => 'client-type',
        ]);

    expect(evaluateBlueprintActionProperty($createAction, 'isVisible', [
        'operation' => 'edit',
        'state' => null,
    ], [
        BaseBlueprintSelect::class => $component,
        SiteBlueprintSelect::class => $component,
    ]))->toBeTrue()
        ->and(evaluateBlueprintActionProperty($createAction, 'isVisible', [
            'operation' => 'edit',
            'state' => $blueprint->getKey(),
        ], [
            BaseBlueprintSelect::class => $component,
            SiteBlueprintSelect::class => $component,
        ]))->toBeFalse()
        ->and(evaluateBlueprintActionProperty($createAction, 'modalHeading', typedInjections: [
            BaseBlueprintSelect::class => $component,
            SiteBlueprintSelect::class => $component,
        ]))->toBe('Create blueprint');

    $createAction->callAfter();
    $editAction->callAfter();

    expect($createCallbackWasCalled)->toBeTrue()
        ->and($editCallbackWasCalled)->toBeTrue();
});

it('hides configurator path hints by default on blueprint selects', function (): void {
    config()->set('capell-admin.show_configurator_type_hint', true);

    $component = mountedBlueprintSelect(SiteBlueprintSelect::make('blueprint_id'));

    $helpers = blueprintSelectLabelHelpers($component);

    expect($helpers)->toHaveCount(1)
        ->and(filamentObjectIcon($helpers[0]))->toBe(Heroicon::QuestionMarkCircle);
});

it('shows configurator path hints only when enabled in admin settings', function (): void {
    config()->set('capell-admin.show_configurator_type_hint', true);

    $settings = resolve(AdminSettings::class);
    $settings->show_configurator_path_hints = true;
    $settings->save();

    app()->forgetInstance(AdminSettings::class);

    $component = mountedBlueprintSelect(SiteBlueprintSelect::make('blueprint_id'));

    $helpers = blueprintSelectLabelHelpers($component);

    expect($helpers)->toHaveCount(2)
        ->and(filamentObjectIcon($helpers[0]))->toBe(Heroicon::QuestionMarkCircle)
        ->and(filamentObjectIcon($helpers[1]))->toBe(Heroicon::OutlinedQuestionMarkCircle);
});

/**
 * @param  array<string, mixed>  $state
 * @param  class-string<Model>|null  $model
 */
function mountedBlueprintSelect(BaseBlueprintSelect $component, array $state = [], ?string $model = null): BaseBlueprintSelect
{
    $schema = Schema::make(Livewire::make()->data($state))
        ->statePath('data')
        ->operation('edit')
        ->model($model)
        ->components([$component]);

    $mounted = $schema->getComponents()[0];

    throw_unless($mounted instanceof BaseBlueprintSelect, RuntimeException::class, 'Expected mounted BlueprintSelect component.');

    $mounted->state(data_get($state, $mounted->getName()));

    return $mounted;
}

/**
 * @param  array<string, mixed>  $namedInjections
 * @param  array<class-string, mixed>  $typedInjections
 */
function evaluateBlueprintActionProperty(Action $action, string $property, array $namedInjections = [], array $typedInjections = []): mixed
{
    $reflectionProperty = new ReflectionProperty($action, $property);

    return $action->evaluate($reflectionProperty->getValue($action), $namedInjections, $typedInjections);
}

function configuredBlueprintSelectAction(BaseBlueprintSelect $component, string $callbackProperty, string $actionName): Action
{
    $reflectionProperty = new ReflectionProperty($component, $callbackProperty);
    $callback = $reflectionProperty->getValue($component);

    throw_unless($callback instanceof Closure, RuntimeException::class, 'Expected blueprint action configuration callback.');

    $action = Action::make($actionName)->schemaComponent($component);

    $configuredAction = $component->evaluate($callback, ['action' => $action]);

    throw_unless($configuredAction instanceof Action, RuntimeException::class, 'Expected configured blueprint action.');

    return $configuredAction;
}

/**
 * @return array<int, Icon>
 */
function blueprintSelectLabelHelpers(BaseBlueprintSelect $component): array
{
    $reflectionMethod = new ReflectionMethod($component, 'getBlueprintSelectLabelHelpers');
    $helpers = $reflectionMethod->invoke($component, null);

    throw_unless(is_array($helpers), RuntimeException::class, 'Expected blueprint label helpers.');

    return $helpers;
}
