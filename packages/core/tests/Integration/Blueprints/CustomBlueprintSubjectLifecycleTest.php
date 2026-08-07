<?php

declare(strict_types=1);

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintSchemaSnapshot;
use Capell\Core\Models\Page;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Capell\Core\Support\Creator\BlueprintCreator;
use Capell\Core\Tests\Integration\Fixtures\CustomSubjectBlueprintInterceptor;

/**
 * End-to-end proof that a package-registered subject inherits the blueprint
 * machinery core previously reserved for Page, Site and Theme: creation through
 * BlueprintCreator, interceptor invocation, and schema-snapshot capture on a
 * later schema change.
 */
const CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY = 'vendor.editorial.collection';

beforeEach(function (): void {
    CustomSubjectBlueprintInterceptor::reset();

    $this->originalSubjectRegistry = resolve(BlueprintSubjectRegistry::class);

    $registry = new BlueprintSubjectRegistry;

    foreach ($this->originalSubjectRegistry->all() as $existingSubject) {
        $registry->register($existingSubject);
    }

    $registry->register(new BlueprintSubjectDescriptorData(
        key: CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY,
        label: 'Collection',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    ));

    app()->instance(BlueprintSubjectRegistry::class, $registry);

    $this->subjectRegistry = $registry;
});

afterEach(function (): void {
    CapellCore::unregisterModelInterceptor(
        Blueprint::class,
        CustomSubjectBlueprintInterceptor::class,
        ['key' => 'default', 'type' => CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY],
    );

    app()->instance(BlueprintSubjectRegistry::class, $this->originalSubjectRegistry);
});

it('runs interceptors when creating a blueprint for a custom subject', function (): void {
    CapellCore::registerModelInterceptor(
        Blueprint::class,
        CustomSubjectBlueprintInterceptor::class,
        ['key' => 'default', 'type' => CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY],
    );

    new BlueprintCreator($this->subjectRegistry)->create(CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY);

    $blueprint = Blueprint::query()
        ->where('type', CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY)
        ->where('key', 'default')
        ->sole();

    expect(CustomSubjectBlueprintInterceptor::$beforeCreateCalls)->toBe(['beforeCreate'])
        ->and(CustomSubjectBlueprintInterceptor::$afterCreatedCalls)->toBe([(string) $blueprint->getKey()])
        ->and($blueprint->admin['notes'] ?? null)->toBe('Added by the package interceptor.')
        ->and($blueprint->type->name)->toBe(CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY)
        ->and($blueprint->type->model)->toBe(Page::class)
        ->and($blueprint->type->isAvailable())->toBeTrue();
});

it('captures a schema snapshot when a custom-subject blueprint changes', function (): void {
    new BlueprintCreator($this->subjectRegistry)->create(CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY);

    $blueprint = Blueprint::query()
        ->where('type', CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY)
        ->where('key', 'default')
        ->sole();

    $adminBefore = $blueprint->getRawOriginal('admin');

    $blueprint->update([
        'admin' => ['configurator' => 'CollectionListing'],
    ]);

    $snapshot = BlueprintSchemaSnapshot::query()
        ->where('blueprint_id', $blueprint->getKey())
        ->sole();

    expect($snapshot->reason)->toBe('blueprint_schema_update')
        ->and($snapshot->blueprint_type)->toBe(CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY)
        ->and($snapshot->blueprint_key)->toBe('default')
        ->and($snapshot->admin_before)->toBe($adminBefore)
        ->and($snapshot->metadata)->toBe(['changed' => ['admin']]);
});

it('records the pre-change subject when a custom-subject blueprint is re-pointed', function (): void {
    new BlueprintCreator($this->subjectRegistry)->create(CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY);

    $blueprint = Blueprint::query()
        ->where('type', CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY)
        ->where('key', 'default')
        ->sole();

    $blueprint->update(['type' => 'page']);

    $snapshot = BlueprintSchemaSnapshot::query()
        ->where('blueprint_id', $blueprint->getKey())
        ->sole();

    expect($snapshot->type_before)->toBe(CORE_LIFECYCLE_CUSTOM_SUBJECT_KEY)
        ->and($snapshot->metadata)->toBe(['changed' => ['type']])
        ->and($blueprint->refresh()->type->name)->toBe('page');
});
