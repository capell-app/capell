<?php

declare(strict_types=1);

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintSubjectEnum;

it('resolves all BlueprintSubjectEnum cases to BlueprintSubjectDescriptorData without closures', function (): void {
    foreach (BlueprintSubjectEnum::cases() as $typeEnum) {
        $descriptor = BlueprintSubjectDescriptorData::fromEnum($typeEnum);

        expect($descriptor->value)->toBe($typeEnum->value)
            ->and($descriptor->label)->toBeString()->not->toBeEmpty()
            ->and($descriptor->key)->toBeString()->not->toBeEmpty()
            ->and($descriptor->model)->toBeString()->not->toBeEmpty();
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

    // All properties must be scalar — Livewire serialises to JSON and
    // closures/objects dehydrate as `{}`, causing "Property type not supported".
    $properties = $descriptor->toArray();
    foreach ($properties as $propertyValue) {
        expect($propertyValue)->toBeString();
    }
});

it('serialises to JSON without information loss', function (): void {
    $descriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Site);

    $json = json_encode($descriptor->toArray());
    expect($json)->toBeString();

    /** @var array<string, string> $decoded */
    $decoded = json_decode((string) $json, associative: true);
    expect($decoded['value'])->toBe('site')
        ->and($decoded['label'])->toBeString()->not->toBeEmpty();
});

it('exposes stable values matching BlueprintSubjectEnum', function (): void {
    $pageDescriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Page);
    $siteDescriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Site);
    $themeDescriptor = BlueprintSubjectDescriptorData::fromEnum(BlueprintSubjectEnum::Theme);

    expect($pageDescriptor->value)->toBe('page')
        ->and($siteDescriptor->value)->toBe('site')
        ->and($themeDescriptor->value)->toBe('theme');
});
