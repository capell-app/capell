<?php

declare(strict_types=1);

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Blueprints\CoreBlueprintSubjects;
use Capell\Core\Support\Json\JsonCodec;

/**
 * Contract test pinning the BlueprintSubjectDescriptorData shape.
 *
 * The descriptor crosses the package boundary — third-party packages construct
 * it and Capell stores it — so its field set, types and defaults are pinned
 * here. A change that breaks these assertions breaks every extension.
 */
it('pins the descriptor field set and constructor defaults', function (): void {
    $descriptor = new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.collection',
        label: 'Collection',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    );

    expect(array_keys($descriptor->toArray()))->toBe([
        'key',
        'label',
        'modelClass',
        'ownerPackage',
        'groups',
        'defaultSchemaSeeder',
    ])
        ->and($descriptor->groups)->toBe([])
        ->and($descriptor->defaultSchemaSeeder)->toBeNull();
});

it('describes every core blueprint subject', function (): void {
    $descriptors = CoreBlueprintSubjects::descriptors();

    expect($descriptors)->toHaveCount(count(BlueprintSubjectEnum::cases()));

    foreach ($descriptors as $descriptor) {
        expect($descriptor)->toBeInstanceOf(BlueprintSubjectDescriptorData::class)
            ->and($descriptor->key)->toBeString()->not->toBeEmpty()
            ->and($descriptor->label)->toBeString()->not->toBeEmpty()
            ->and($descriptor->modelClass)->toBeString()->not->toBeEmpty()
            ->and($descriptor->ownerPackage)->toBe(CoreBlueprintSubjects::OWNER_PACKAGE)
            ->and($descriptor->groups)->toBeArray()->not->toBeEmpty()
            ->and($descriptor->defaultSchemaSeeder)->toBeString()->not->toBeEmpty();
    }
});

it('exposes the built-in subject keys named by BlueprintSubjectEnum', function (): void {
    $keys = array_map(
        fn (BlueprintSubjectDescriptorData $descriptor): string => $descriptor->key,
        CoreBlueprintSubjects::descriptors(),
    );

    expect($keys)->toBe(['page', 'site', 'theme'])
        ->and($keys)->toBe(array_map(
            fn (BlueprintSubjectEnum $subject): string => $subject->getKey(),
            BlueprintSubjectEnum::cases(),
        ));
});

it('produces Livewire-safe plain-string properties (no closures)', function (): void {
    $descriptor = CoreBlueprintSubjects::descriptors()[0];

    // Properties must remain Livewire-safe scalars or arrays — closures/objects
    // dehydrate as `{}`, causing "Property type not supported".
    $properties = $descriptor->toArray();

    expect($properties['key'])->toBeString()
        ->and($properties['label'])->toBeString()
        ->and($properties['modelClass'])->toBeString()
        ->and($properties['ownerPackage'])->toBeString()
        ->and($properties['groups'])->toBeArray()
        ->and($properties['defaultSchemaSeeder'])->toBeString();
});

it('serialises to JSON without information loss', function (): void {
    $siteDescriptor = collect(CoreBlueprintSubjects::descriptors())
        ->firstOrFail(fn (BlueprintSubjectDescriptorData $descriptor): bool => $descriptor->key === BlueprintSubjectEnum::Site->getKey());

    $decoded = JsonCodec::decodeArray(JsonCodec::encode($siteDescriptor->toArray()));

    expect($decoded['key'])->toBe('site')
        ->and($decoded['label'])->toBeString()->not->toBeEmpty()
        ->and($decoded['modelClass'])->toBeString()->not->toBeEmpty()
        ->and($decoded['ownerPackage'])->toBe(CoreBlueprintSubjects::OWNER_PACKAGE);
});

it('allows every group when no groups are declared', function (): void {
    $descriptor = new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.collection',
        label: 'Collection',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    );

    foreach (BlueprintGroupEnum::cases() as $group) {
        expect($descriptor->allowsGroup($group))->toBeTrue();
    }
});

it('allows only the declared groups when groups are listed', function (): void {
    $descriptor = new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.collection',
        label: 'Collection',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
        groups: [BlueprintGroupEnum::Default],
    );

    expect($descriptor->allowsGroup(BlueprintGroupEnum::Default))->toBeTrue()
        ->and($descriptor->allowsGroup(BlueprintGroupEnum::System))->toBeFalse();
});
