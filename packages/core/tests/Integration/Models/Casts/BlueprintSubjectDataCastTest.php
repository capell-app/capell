<?php

declare(strict_types=1);

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Exceptions\UnknownBlueprintSubjectException;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Illuminate\Support\Facades\DB;

// The container registry freezes at boot, so a test needing a custom subject
// swaps a fresh instance in. Restoration is unconditional so a failing test
// cannot leak the swapped registry into later tests.
beforeEach(function (): void {
    $this->originalSubjectRegistry = resolve(BlueprintSubjectRegistry::class);
});

afterEach(function (): void {
    app()->instance(BlueprintSubjectRegistry::class, $this->originalSubjectRegistry);
});

/**
 * Swap in a registry that also knows a package-contributed subject.
 */
function registerCustomBlueprintSubject(string $key = 'vendor.editorial.collection'): BlueprintSubjectDescriptorData
{
    $subject = new BlueprintSubjectDescriptorData(
        key: $key,
        label: 'Collection',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    );

    $registry = new BlueprintSubjectRegistry;

    foreach (resolve(BlueprintSubjectRegistry::class)->all() as $existingSubject) {
        $registry->register($existingSubject);
    }

    $registry->register($subject);

    app()->instance(BlueprintSubjectRegistry::class, $registry);

    return $subject;
}

it('round-trips a built-in subject key through the cast', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne();

    $storedType = DB::table('blueprints')->where('id', $blueprint->getKey())->value('type');

    expect($storedType)->toBe(BlueprintSubjectEnum::Page->getKey())
        ->and($blueprint->refresh()->type)->toBeInstanceOf(PageTypeData::class)
        ->and($blueprint->type->name)->toBe('page')
        ->and($blueprint->type->model)->toBe(Page::class)
        ->and($blueprint->type->isAvailable())->toBeTrue();
});

it('round-trips a package-registered subject key through the cast', function (): void {
    $subject = registerCustomBlueprintSubject();

    $blueprint = Blueprint::factory()->type($subject->key)->createOne();

    $storedType = DB::table('blueprints')->where('id', $blueprint->getKey())->value('type');

    expect($storedType)->toBe('vendor.editorial.collection')
        ->and($blueprint->refresh()->type->name)->toBe('vendor.editorial.collection')
        ->and($blueprint->type->model)->toBe(Page::class)
        ->and($blueprint->type->label)->toBe('Collection')
        ->and($blueprint->type->isAvailable())->toBeTrue();
});

it('accepts a BlueprintSubjectEnum and a PageTypeData on write', function (): void {
    $fromEnum = Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Site]);
    $fromData = Blueprint::factory()->createOne([
        'type' => new PageTypeData(name: BlueprintSubjectEnum::Theme->getKey(), model: null),
    ]);

    expect(DB::table('blueprints')->where('id', $fromEnum->getKey())->value('type'))->toBe('site')
        ->and(DB::table('blueprints')->where('id', $fromData->getKey())->value('type'))->toBe('theme');
});

it('fails closed when writing an unregistered subject key', function (): void {
    expect(fn (): Blueprint => Blueprint::factory()->createOne(['type' => 'vendor.missing.subject']))
        ->toThrow(UnknownBlueprintSubjectException::class, 'is not registered');

    expect(DB::table('blueprints')->where('type', 'vendor.missing.subject')->count())->toBe(0);
});

it('fails closed when writing a value that is not a subject reference at all', function (): void {
    expect(fn (): Blueprint => Blueprint::factory()->createOne(['type' => 123]))
        ->toThrow(UnknownBlueprintSubjectException::class, 'must be a subject key');

    expect(fn (): Blueprint => Blueprint::factory()->createOne(['type' => ['page']]))
        ->toThrow(UnknownBlueprintSubjectException::class, 'must be a subject key');
});

it('degrades to an unavailable subject when reading an orphaned row', function (): void {
    // Write through a registry that knows the subject, then read it back after
    // the contributing package has "gone away".
    $subject = registerCustomBlueprintSubject('vendor.uninstalled.collection');
    $blueprint = Blueprint::factory()->type($subject->key)->createOne();

    app()->instance(BlueprintSubjectRegistry::class, $this->originalSubjectRegistry);

    $orphaned = Blueprint::query()->findOrFail($blueprint->getKey());

    expect($orphaned->type)->toBeInstanceOf(PageTypeData::class)
        ->and($orphaned->type->name)->toBe('vendor.uninstalled.collection')
        ->and($orphaned->type->model)->toBeNull()
        ->and($orphaned->type->isAvailable())->toBeFalse()
        ->and($orphaned->type->getLabel())->toContain('vendor.uninstalled.collection');
});

it('casts a reserved internal navigation type without treating it as a subject', function (): void {
    $blueprint = Blueprint::factory()->navigation()->createOne();

    expect(DB::table('blueprints')->where('id', $blueprint->getKey())->value('type'))
        ->toBe(Blueprint::NAVIGATION_TYPE)
        ->and($blueprint->refresh()->type)->toBeInstanceOf(PageTypeData::class)
        ->and($blueprint->type->name)->toBe(Blueprint::NAVIGATION_TYPE)
        ->and(Blueprint::isReservedInternalType(Blueprint::NAVIGATION_TYPE))->toBeTrue()
        ->and(resolve(BlueprintSubjectRegistry::class)->descriptorOrNull(Blueprint::NAVIGATION_TYPE))->toBeNull();
});
