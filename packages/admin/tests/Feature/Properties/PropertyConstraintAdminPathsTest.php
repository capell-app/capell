<?php

declare(strict_types=1);

use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Support\Agent\AgentAdminConfirmationStore;
use Capell\Admin\Support\Agent\AgentAdminToolInvocationService;
use Capell\Admin\Support\Agent\AgentAdminToolRegistry;
use Capell\Admin\Support\Properties\PropertyConstraintPageAuthoringValidator;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Actions\Properties\PromoteBlueprintFieldAction;
use Capell\Core\Actions\Properties\UpdatePropertyDefinitionConstraintsAction;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Support\Properties\PropertyValueValidator;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('admin');

/**
 * @param  array<string, mixed>  $constraints
 */
function adminConstrainedProperty(Page $page, string $key, PropertyType $type, array $constraints, ?string $ownerPackage = null): PropertyDefinition
{
    $set = PropertySet::factory()->create(['key' => 'test.admin.' . $key, 'name' => 'Catalogue', 'owner_package' => $ownerPackage]);
    $definition = PropertyDefinition::factory()->withConstraints($constraints)->create([
        'property_set_id' => $set->id,
        'key' => $key,
        'type' => $type,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);

    return $definition;
}

it('rejects a value that breaks a property rule when an agent previews the write', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne();
    adminConstrainedProperty($page, 'sku', PropertyType::Text, ['pattern' => '[A-Z]{3}-[0-9]{4}']);

    $this->withSession(['capell.current_site_id' => $page->site_id])
        ->postJson(route('capell-admin.agent.tools.invoke'), [
            'tool' => 'admin.page.properties.write',
            'payload' => ['page_id' => $page->id, 'property_key' => 'sku', 'value' => 'abc-1234'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.value.0', 'Sku is not in the format this field expects. Check the field help for an example and try again.');

    $this->withSession(['capell.current_site_id' => $page->site_id])
        ->postJson(route('capell-admin.agent.tools.invoke'), [
            'tool' => 'admin.page.properties.write',
            'payload' => ['page_id' => $page->id, 'property_key' => 'sku', 'value' => 'ABC-1234'],
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'confirmation_required');

    expect(PagePropertyValue::query()->where('page_id', $page->id)->exists())->toBeFalse();
});

it('writes a confirmed agent value only while it still meets the property rules', function (): void {
    $user = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->withTranslations()->createOne();
    $definition = adminConstrainedProperty($page, 'sku', PropertyType::Text, ['pattern' => '[A-Z]{3}-[0-9]{4}']);
    $service = new AgentAdminToolInvocationService(new AgentAdminToolRegistry, new AgentAdminConfirmationStore);
    $preview = static fn (string $value): AgentAdminToolResultData => $service->invoke(
        'admin.page.properties.write',
        ['page_id' => $page->id, 'property_key' => 'sku', 'value' => $value],
        $user,
        $page->site_id,
        sessionId: 'agent-session',
    );

    $token = $preview('ABC-1234')->confirmationToken;
    $executed = $service->invoke('admin.page.properties.write', [], $user, $page->site_id, $token, 'agent-session');

    expect($executed->mode)->toBe('executed')
        ->and(PagePropertyValue::query()->where('property_definition_id', $definition->id)->value('value_text'))->toBe('ABC-1234');

    // A rule tightened between preview and confirmation still applies.
    $token = $preview('XYZ-9876')->confirmationToken;
    UpdatePropertyDefinitionConstraintsAction::run($definition, ['pattern' => '[A-Z]{3}-[0-9]{4}', 'max_length' => 4]);

    expect(fn (): AgentAdminToolResultData => $service->invoke('admin.page.properties.write', [], $user, $page->site_id, $token, 'agent-session'))
        ->toThrow(ValidationException::class);

    expect(PagePropertyValue::query()->where('property_definition_id', $definition->id)->value('value_text'))->toBe('ABC-1234');
});

it('shows a property rule error on the promoted page field and keeps the saved page unchanged', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne(['name' => 'Spring sale']);
    $definition = adminConstrainedProperty($page, 'display_name', PropertyType::Text, ['max_length' => 12]);
    PromoteBlueprintFieldAction::run(Blueprint::query()->findOrFail($page->blueprint_id), $definition, 'name');

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['name' => 'A name that is far too long'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($page->refresh()->name)->toBe('Spring sale');

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['name' => 'Summer sale'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->name)->toBe('Summer sale')
        ->and(PagePropertyValue::query()
            ->where('page_id', $page->id)
            ->where('property_definition_id', $definition->id)
            ->value('value_text'))->toBe('Summer sale');
});

it('saves property rules from the blueprint list and reports an unusable format on its field', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->createOne();
    $definition = adminConstrainedProperty($page, 'sku', PropertyType::Text, []);
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);

    Livewire::test(ManageBlueprints::class)
        ->callTableAction('propertyRules', $blueprint, [
            'property_definition_id' => $definition->id,
            'pattern' => '[A-Z',
        ])
        ->assertHasTableActionErrors(['pattern']);

    expect($definition->refresh()->constraints)->toBeNull();

    Livewire::test(ManageBlueprints::class)
        ->callTableAction('propertyRules', $blueprint, [
            'property_definition_id' => $definition->id,
            'max_length' => 8,
            'pattern' => '[A-Z]{3}-[0-9]{4}',
            'unique_per_site' => true,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('capell-admin::property_rules.saved'));

    expect($definition->refresh()->constraints?->toStored())->toBe([
        'version' => 1,
        'max_length' => 8,
        'pattern' => '[A-Z]{3}-[0-9]{4}',
        'unique_per_site' => true,
    ]);
});

it('hides the rules action when every attached property set is managed by a package', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->createOne();
    adminConstrainedProperty($page, 'gtin', PropertyType::Text, [], 'capell-app/commerce');

    Livewire::test(ManageBlueprints::class)
        ->assertTableActionHidden('propertyRules', Blueprint::query()->findOrFail($page->blueprint_id));
});

it('keeps property rules, which every site shares, away from site-scoped administrators', function (): void {
    $page = Page::factory()->createOne();
    adminConstrainedProperty($page, 'sku', PropertyType::Text, []);
    $user = ScopedAdminUser::make(collect([$page->site_id]));
    $user->save();

    test()->actingAs($user);

    Livewire::test(ManageBlueprints::class)
        ->assertTableActionHidden('propertyRules', Blueprint::query()->findOrFail($page->blueprint_id));
});

it('blocks a saved value that breaks a newer rule outside production, even when the editor leaves it unchanged', function (string $environment): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne(['name' => 'An established page name']);
    $definition = adminConstrainedProperty($page, 'display_name', PropertyType::Text, ['max_length' => 12]);
    PromoteBlueprintFieldAction::run(Blueprint::query()->findOrFail($page->blueprint_id), $definition, 'name');

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['name' => 'An established page name'])
        ->call('save')
        ->assertHasFormErrors(['name' => 'Display Name must be 12 characters or fewer. Shorten the text.']);

    app()->detectEnvironment(static fn (): string => $environment);

    expect(PropertyValueValidator::enforcesUntouchedValues())->toBeTrue()
        ->and(fn () => resolve(PropertyConstraintPageAuthoringValidator::class)->validate(['name' => 'An established page name'], $page))
        ->toThrow(ValidationException::class, 'Display Name must be 12 characters or fewer. Shorten the text.');
})->with(['testing', 'local', 'staging']);

it('lets the save through in production when an unchanged saved value breaks a newer rule, then warns the editor and logs it', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne(['name' => 'An established page name']);
    $definition = adminConstrainedProperty($page, 'display_name', PropertyType::Text, ['max_length' => 12]);
    PromoteBlueprintFieldAction::run(Blueprint::query()->findOrFail($page->blueprint_id), $definition, 'name');
    app()->detectEnvironment(static fn (): string => 'production');
    $log = Log::spy();
    $validator = resolve(PropertyConstraintPageAuthoringValidator::class);

    expect(PropertyValueValidator::enforcesUntouchedValues())->toBeFalse();

    $validator->validate(['name' => 'An established page name', 'order' => 5], $page);

    Notification::assertNotified(__('capell-admin::property_rules.untouched_breach_title', ['field' => 'Name']));
    $log->shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context): bool => $context === [
            'page_id' => $page->id,
            'property' => 'test.admin.display_name.display_name',
            'field' => 'name',
        ])
        ->once();

    // The page save behind the editor is not blocked by the untouched field either.
    $page->order = 5;
    $page->save();

    expect($page->refresh()->order)->toBe(5)
        ->and($page->name)->toBe('An established page name');

    // A value the editor changes is still enforced in production.
    expect(fn () => $validator->validate(['name' => 'Another far too long name'], $page))
        ->toThrow(ValidationException::class, 'Display Name must be 12 characters or fewer. Shorten the text.');
});

it('stays silent in production when an unchanged saved value meets its rules', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne(['name' => 'Short name']);
    $definition = adminConstrainedProperty($page, 'display_name', PropertyType::Text, ['max_length' => 12]);
    PromoteBlueprintFieldAction::run(Blueprint::query()->findOrFail($page->blueprint_id), $definition, 'name');
    app()->detectEnvironment(static fn (): string => 'production');
    $log = Log::spy();

    resolve(PropertyConstraintPageAuthoringValidator::class)->validate(['name' => 'Short name'], $page);

    $log->shouldNotHaveReceived('warning');
});

it('checks promoted fields against the blueprint the form moves the page to', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne(['name' => 'An established page name']);
    $strict = Blueprint::factory()->page()->create();
    $set = PropertySet::factory()->create();
    $definition = PropertyDefinition::factory()->withConstraints(['max_length' => 12])->create([
        'property_set_id' => $set->id,
        'key' => 'display_name',
        'type' => PropertyType::Text,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $strict->id, 'property_set_id' => $set->id]);
    PromoteBlueprintFieldAction::run($strict, $definition, 'name');
    $validator = resolve(PropertyConstraintPageAuthoringValidator::class);

    $validator->validate(['name' => 'An established page name'], $page);

    try {
        $validator->validate(['blueprint_id' => $strict->id, 'name' => 'An established page name'], $page);
        $this->fail('Expected the move to the stricter blueprint to be checked.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toBe(['data.name' => ['Display Name must be 12 characters or fewer. Shorten the text.']]);
    }
});
