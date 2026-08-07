<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Admin\Support\Blueprints\BlueprintSubjectOptions;
use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Support\Blueprints\CoreBlueprintSubjects;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('type');

const CUSTOM_SUBJECT_KEY = 'structured-content.collection';

const CUSTOM_SUBJECT_LABEL = 'Collection';

const CUSTOM_SUBJECT_OWNER = 'capell-app/structured-content-library';

// The registry is a container singleton frozen at boot, so tests that need an
// unfrozen one swap a fresh instance in. Restoration must happen even when the
// test fails part-way, or the swapped registry leaks into later tests.
beforeEach(function (): void {
    test()->actingAsAdmin();
    $this->originalSubjectRegistry = resolve(BlueprintSubjectRegistry::class);
});

afterEach(function (): void {
    app()->instance(BlueprintSubjectRegistry::class, $this->originalSubjectRegistry);
});

it('offers a package-registered subject alongside the built-ins in the create options', function (): void {
    registerBlueprintSubjectsForTest(withCustomSubject: true);

    expect(BlueprintSubjectOptions::all())
        ->toHaveKey(CUSTOM_SUBJECT_KEY, CUSTOM_SUBJECT_LABEL)
        ->toHaveKey('page')
        ->and(BlueprintSubjectOptions::label(CUSTOM_SUBJECT_KEY))->toBe(CUSTOM_SUBJECT_LABEL)
        ->and(BlueprintSubjectOptions::ownerPackage(CUSTOM_SUBJECT_KEY))->toBe(CUSTOM_SUBJECT_OWNER);
});

it('limits the option list to subjects allowing the requested group', function (): void {
    registerBlueprintSubjectsForTest(withCustomSubject: true);

    expect(BlueprintSubjectOptions::forGroup(BlueprintGroupEnum::System))
        ->toHaveKey('page')
        ->not->toHaveKey(CUSTOM_SUBJECT_KEY)
        ->not->toHaveKey('site');
});

it('creates and filters blueprints for a package-registered subject', function (): void {
    registerBlueprintSubjectsForTest(withCustomSubject: true);

    $collectionBlueprint = Blueprint::query()->create([
        'name' => 'Product collection',
        'key' => 'product-collection',
        'type' => CUSTOM_SUBJECT_KEY,
        'admin' => ['configurator' => 'Default'],
    ]);
    Blueprint::factory()->page()->create(['key' => 'standard-page', 'name' => 'Standard page']);

    expect($collectionBlueprint->getRawOriginal('type'))->toBe(CUSTOM_SUBJECT_KEY);

    $component = Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertCountTableRecords(2)
        ->set('activeTab', CUSTOM_SUBJECT_KEY)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$collectionBlueprint]);

    expect(blueprintTabLabel($component->instance(), CUSTOM_SUBJECT_KEY))->toBe(CUSTOM_SUBJECT_LABEL);
});

it('lists blueprints whose subject package is gone under an unavailable subject tab', function (): void {
    registerBlueprintSubjectsForTest(withCustomSubject: true);

    $orphanedBlueprint = Blueprint::query()->create([
        'name' => 'Orphaned collection',
        'key' => 'orphaned-collection',
        'type' => CUSTOM_SUBJECT_KEY,
        'admin' => ['configurator' => 'Default'],
    ]);

    // The package is uninstalled: its descriptor disappears, its rows do not.
    registerBlueprintSubjectsForTest(withCustomSubject: false);

    $component = Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$orphanedBlueprint])
        ->set('activeTab', CUSTOM_SUBJECT_KEY)
        ->assertSuccessful()
        ->assertCountTableRecords(1);

    expect(blueprintTabLabel($component->instance(), CUSTOM_SUBJECT_KEY))
        ->toBe(__('capell-admin::generic.unavailable_subject', ['key' => CUSTOM_SUBJECT_KEY]))
        ->and(BlueprintSubjectOptions::isAvailable(CUSTOM_SUBJECT_KEY))->toBeFalse()
        ->and(BlueprintSubjectOptions::ownerPackage(CUSTOM_SUBJECT_KEY))->toBeNull()
        ->and($orphanedBlueprint->refresh()->type->isAvailable())->toBeFalse();
});

/**
 * Swap in an unfrozen registry holding the core subjects, optionally plus a
 * subject a package would contribute at boot.
 */
function registerBlueprintSubjectsForTest(bool $withCustomSubject): void
{
    $registry = new BlueprintSubjectRegistry;

    foreach (CoreBlueprintSubjects::descriptors() as $descriptor) {
        $registry->register($descriptor);
    }

    if ($withCustomSubject) {
        $registry->register(new BlueprintSubjectDescriptorData(
            key: CUSTOM_SUBJECT_KEY,
            label: CUSTOM_SUBJECT_LABEL,
            modelClass: Page::class,
            ownerPackage: CUSTOM_SUBJECT_OWNER,
            groups: [BlueprintGroupEnum::Default],
        ));
    }

    app()->instance(BlueprintSubjectRegistry::class, $registry);
}

function blueprintTabLabel(ManageBlueprints $page, string $subjectKey): ?string
{
    $tab = $page->getTabs()[$subjectKey] ?? null;
    $label = $tab?->getLabel();

    if ($label instanceof Htmlable) {
        return $label->toHtml();
    }

    return $label;
}
