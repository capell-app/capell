<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\BlueprintSelect as BaseBlueprintSelect;
use Capell\Admin\Filament\Components\Forms\DefaultToggle;
use Capell\Admin\Filament\Components\Forms\Page\BlueprintSelect as PageBlueprintSelect;
use Capell\Admin\Filament\Components\Forms\Site\BlueprintSelect as SiteBlueprintSelect;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures\PageTranslationsRepeaterLivewireForTest;
use Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures\StringTypedBlueprintSelectForTest;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\Action;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;

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

it('points the shared select at any subject key, degrading when it is unregistered', function (): void {
    $component = BaseBlueprintSelect::make('blueprint_id');

    expect($component->getBlueprint())->toBeNull()
        ->and($component->subject(BlueprintSubjectEnum::Theme->getKey())->getBlueprint())
        ->toBe(BlueprintSubjectEnum::Theme->value)
        ->and($component->subject('structured-content.collection')->getBlueprint())
        ->toBe('structured-content.collection');
});

it('adds the system blueprint group only when the subject descriptor allows it', function (): void {
    $pageSelect = PageBlueprintSelect::make('blueprint_id');
    $siteScopedSelect = PageBlueprintSelect::make('blueprint_id')->subject(BlueprintSubjectEnum::Site->getKey());
    $orphanedSelect = PageBlueprintSelect::make('blueprint_id')->subject('structured-content.collection');

    expect(subjectAllowsSystemGroup($pageSelect))->toBeTrue()
        ->and(subjectAllowsSystemGroup($siteScopedSelect))->toBeFalse()
        ->and(subjectAllowsSystemGroup($orphanedSelect))->toBeFalse();
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

it('accepts ordinary page blueprints through the legacy page group alias', function (bool $callableGroup): void {
    $defaultBlueprint = Blueprint::factory()->page()->group(BlueprintGroupEnum::Default->value)->createOne();
    $ungroupedBlueprint = Blueprint::factory()->page()->createOne();
    $disabledBlueprint = Blueprint::factory()->page()->group(BlueprintGroupEnum::Default->value)->createOne(['status' => false]);
    $customBlueprint = Blueprint::factory()->page()->group('articles')->createOne();
    $systemBlueprint = Blueprint::factory()->page()->group(BlueprintGroupEnum::System->value)->createOne();
    $siteBlueprint = Blueprint::factory()->site()->group(BlueprintGroupEnum::Default->value)->createOne();

    $component = mountedBlueprintSelect(
        PageBlueprintSelect::make('blueprint_id')
            ->pageGroup($callableGroup ? static fn (): string => 'page' : 'page')
            ->withSystemTypes(false)
            ->withRelation(),
        ['blueprint_id' => $defaultBlueprint->getKey()],
        Page::class,
    );

    expect($component->getOptionsFromRelationship())
        ->toHaveKey($defaultBlueprint->getKey())
        ->toHaveKey($ungroupedBlueprint->getKey())
        ->not->toHaveKey($disabledBlueprint->getKey())
        ->not->toHaveKey($customBlueprint->getKey())
        ->not->toHaveKey($systemBlueprint->getKey())
        ->not->toHaveKey($siteBlueprint->getKey());
    expect($component->getOptionLabel(withDefault: false))->not->toBeEmpty()
        ->and($component->getInValidationRuleValues())->toBeNull();
})->with([false, true]);

it('retains explicit custom page group and enabled subject constraints', function (): void {
    $customBlueprint = Blueprint::factory()->page()->group('articles')->createOne();
    $disabledBlueprint = Blueprint::factory()->page()->group('articles')->createOne(['status' => false]);
    $defaultBlueprint = Blueprint::factory()->page()->group(BlueprintGroupEnum::Default->value)->createOne();
    $otherBlueprint = Blueprint::factory()->page()->group('events')->createOne();
    $siteBlueprint = Blueprint::factory()->site()->group('articles')->createOne();

    $component = mountedBlueprintSelect(
        PageBlueprintSelect::make('blueprint_id')
            ->pageGroup('articles')
            ->withSystemTypes(false)
            ->withRelation(),
        model: Page::class,
    );

    expect($component->getOptionsFromRelationship())
        ->toHaveKey($customBlueprint->getKey())
        ->not->toHaveKey($disabledBlueprint->getKey())
        ->not->toHaveKey($defaultBlueprint->getKey())
        ->not->toHaveKey($otherBlueprint->getKey())
        ->not->toHaveKey($siteBlueprint->getKey());
});

it('offers system blueprint options only for enabled system subjects', function (bool $withSystemTypes, string $subject, bool $expectedSystem): void {
    $defaultBlueprint = Blueprint::factory()->type($subject)->group(BlueprintGroupEnum::Default->value)->createOne();
    $systemBlueprint = Blueprint::factory()->type($subject)->group(BlueprintGroupEnum::System->value)->createOne();
    $component = mountedBlueprintSelect(
        PageBlueprintSelect::make('blueprint_id')
            ->subject($subject)
            ->withSystemTypes($withSystemTypes)
            ->withRelation(),
        model: $subject === BlueprintSubjectEnum::Page->value ? Page::class : Site::class,
    );
    $options = $component->getOptionsFromRelationship();

    expect($options)->toHaveKey($defaultBlueprint->getKey())
        ->and(array_key_exists($systemBlueprint->getKey(), $options ?? []))->toBe($expectedSystem);
})->with([
    'page system types enabled' => [true, BlueprintSubjectEnum::Page->value, true],
    'page system types hidden' => [false, BlueprintSubjectEnum::Page->value, false],
    'site subject has no system group' => [true, BlueprintSubjectEnum::Site->value, false],
]);

it('binds nested blueprint forms to their own model and selected record', function (): void {
    $blueprint = Blueprint::factory()->site()->create(['name' => 'Embedded site type']);
    $component = mountedBlueprintSelect(
        SiteBlueprintSelect::make('blueprint_id')->withRelation()->withCreateForm()->withEditForm(),
        ['blueprint_id' => $blueprint->getKey()],
        Site::class,
    );
    $create = $component->getCreateOptionActionForm(Schema::make(Livewire::make()));
    $edit = $component->getEditOptionActionForm(Schema::make(Livewire::make()));
    throw_if(! $create instanceof Schema || ! $edit instanceof Schema, RuntimeException::class, 'Nested option forms must return schemas.');

    $record = $edit->getRecord();
    throw_unless($record instanceof Blueprint, RuntimeException::class, 'Nested edit form must retain its selected record.');

    $default = array_find($edit->getFlatComponents(withHidden: true), static fn (mixed $component): bool => $component instanceof DefaultToggle);
    throw_unless($default instanceof DefaultToggle, RuntimeException::class, 'Nested option form must expose its default toggle.');

    expect($default->getLabel())->not->toBeEmpty();

    expect($create->getModel())->toBe(Blueprint::class)
        ->and($edit->getModel())->toBe(Blueprint::class)
        ->and($record->getKey())->toBe($blueprint->getKey());
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
    $livewire = $component instanceof PageBlueprintSelect
        ? new PageTranslationsRepeaterLivewireForTest
        : Livewire::make();
    $schema = Schema::make($livewire->data($state))
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

function subjectAllowsSystemGroup(PageBlueprintSelect $component): bool
{
    $reflectionMethod = new ReflectionMethod($component, 'subjectAllowsSystemGroup');

    return (bool) $reflectionMethod->invoke($component);
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

it('authorises inline blueprint creation and editing independently of page permissions', function (): void {
    $blueprint = Blueprint::factory()->page()->create();
    $actor = User::factory()->create();
    foreach (['Create:Page', 'Update:Page'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    test()->actingAs($actor);
    $component = mountedBlueprintSelect(
        PageBlueprintSelect::make('blueprint_id')->withRelation()->withCreateForm()->withEditForm(),
        ['blueprint_id' => $blueprint->id],
        Page::class,
    );

    expect(expectPresent($component->getCreateOptionAction())->isAuthorized())->toBeFalse()
        ->and(expectPresent($component->getEditOptionAction())->isAuthorized())->toBeFalse();

    $actor->givePermissionTo(Permission::findOrCreate('Create:Blueprint', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('Update:Blueprint', 'web'));
    expect(expectPresent($component->getCreateOptionAction())->isAuthorized())->toBeTrue()
        ->and(expectPresent($component->getEditOptionAction())->isAuthorized())->toBeTrue();
});
