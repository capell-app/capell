<?php

declare(strict_types=1);

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintSubjectEnum;

it('resolves all BlueprintSubjectEnum cases to BlueprintSubjectDescriptorData without closures', function (): void {
    foreach (BlueprintSubjectEnum::cases() as $typeEnum) {
        $descriptor = BlueprintSubjectDescriptorData::fromEnum($typeEnum);

        expect($descriptor->key)->toBe($typeEnum->value)
            ->and($descriptor->label)->toBeString()->not->toBeEmpty()
            ->and($descriptor->modelClass)->toBeString()->not->toBeEmpty()
            ->and($descriptor->ownerPackage)->toBe('capell-app/core')
            ->and($descriptor->groups)->toBe([])
            ->and($descriptor->defaultSchemaSeeder)->toBeString()->not->toBeEmpty();
    }
});

it('round-trips BlueprintSubjectEnum through BlueprintSubjectDescriptorData', function (): void {
    foreach (BlueprintSubjectEnum::cases() as $typeEnum) {
        $descriptor = BlueprintSubjectDescriptorData::fromEnum($typeEnum);

        expect($descriptor->toEnum())->toBe($typeEnum);
    }
});

it('produces Livewire-safe plain-string properties (no closures)', function (): void {
    $descriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Page);

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
    $descriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Site);

    $json = json_encode($descriptor->toArray());
    expect($json)->toBeString();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $json, associative: true);
    expect($decoded['key'])->toBe('site')
        ->and($decoded['label'])->toBeString()->not->toBeEmpty()
        ->and($decoded['modelClass'])->toBeString()->not->toBeEmpty()
        ->and($decoded['ownerPackage'])->toBe('capell-app/core');
});

it('exposes stable values matching BlueprintSubjectEnum', function (): void {
    $pageDescriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Page);
    $siteDescriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Site);
    $themeDescriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Theme);

    expect($pageDescriptor->key)->toBe('page')
        ->and($siteDescriptor->key)->toBe('site')
        ->and($themeDescriptor->key)->toBe('theme');
});
