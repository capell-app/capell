<?php

declare(strict_types=1);

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\BlueprintSubjectRegistry;

it('registers built-in-shaped and custom blueprint subjects', function (): void {
    $registry = new BlueprintSubjectRegistry;
    $subject = new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.collection',
        label: 'Collection',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    );

    $registry->register($subject);

    expect($registry->descriptor('vendor.editorial.collection'))->toBe($subject)
        ->and($registry->has('vendor.editorial.collection'))->toBeTrue();
});

it('resolves built-in enum keys and rejects unknown or duplicate subjects', function (): void {
    $registry = new BlueprintSubjectRegistry;
    $page = new BlueprintSubjectDescriptorData(
        key: BlueprintSubjectEnum::Page->getKey(),
        label: 'Page',
        modelClass: Page::class,
        ownerPackage: 'capell-app/core',
    );
    $registry->register($page);

    expect($registry->descriptor(BlueprintSubjectEnum::Page))->toBe($page)
        ->and(fn (): BlueprintSubjectDescriptorData => $registry->descriptor('missing.subject'))
        ->toThrow(InvalidArgumentException::class, 'Registered subjects');

    expect(fn (): BlueprintSubjectRegistry => $registry->register($page))
        ->toThrow(InvalidArgumentException::class, 'already registered');
});

it('rejects invalid subject keys, models, and late registration', function (): void {
    $registry = new BlueprintSubjectRegistry;

    expect(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
        key: 'Not Valid',
        label: 'Invalid',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    )))->toThrow(InvalidArgumentException::class, 'lowercase kebab-case');

    expect(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.invalid',
        label: 'Invalid',
        modelClass: stdClass::class,
        ownerPackage: 'vendor/editorial',
    )))->toThrow(InvalidArgumentException::class, 'must extend');

    $registry->freeze();

    expect(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.late',
        label: 'Late',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    )))->toThrow(InvalidArgumentException::class, 'frozen');
});
