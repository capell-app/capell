<?php

declare(strict_types=1);

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Exceptions\BlueprintSubjectRegistrationException;
use Capell\Core\Exceptions\UnknownBlueprintSubjectException;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
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
        ->and($registry->has('vendor.editorial.collection'))->toBeTrue()
        ->and($registry->keys())->toBe(['vendor.editorial.collection'])
        ->and($registry->all())->toBe(['vendor.editorial.collection' => $subject]);
});

it('resolves built-in enum keys', function (): void {
    $registry = new BlueprintSubjectRegistry;
    $pageSubject = new BlueprintSubjectDescriptorData(
        key: BlueprintSubjectEnum::Page->getKey(),
        label: 'Page',
        modelClass: Page::class,
        ownerPackage: 'capell-app/core',
    );

    $registry->register($pageSubject);

    expect($registry->descriptor(BlueprintSubjectEnum::Page))->toBe($pageSubject)
        ->and($registry->has(BlueprintSubjectEnum::Page))->toBeTrue();
});

it('throws an unknown subject exception when resolving an unregistered key', function (): void {
    $registry = new BlueprintSubjectRegistry;

    expect(fn (): BlueprintSubjectDescriptorData => $registry->descriptor('missing.subject'))
        ->toThrow(UnknownBlueprintSubjectException::class, 'Registered subjects');
});

it('returns null instead of throwing for an unknown key on the tolerant read path', function (): void {
    $registry = new BlueprintSubjectRegistry;

    expect($registry->descriptorOrNull('missing.subject'))->toBeNull()
        ->and($registry->has('missing.subject'))->toBeFalse();
});

it('attributes subjects to the package that registered them', function (): void {
    $registry = new BlueprintSubjectRegistry;
    $collectionSubject = new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.collection',
        label: 'Collection',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    );
    $glossarySubject = new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.glossary',
        label: 'Glossary',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    );
    $otherSubject = new BlueprintSubjectDescriptorData(
        key: 'vendor.other.microsite',
        label: 'Microsite',
        modelClass: Site::class,
        ownerPackage: 'vendor/other',
    );

    $registry->register($collectionSubject)
        ->register($glossarySubject)
        ->register($otherSubject);

    expect($registry->ownedBy('vendor/editorial'))->toBe([
        'vendor.editorial.collection' => $collectionSubject,
        'vendor.editorial.glossary' => $glossarySubject,
    ])
        ->and($registry->ownedBy('vendor/other'))->toBe(['vendor.other.microsite' => $otherSubject])
        ->and($registry->ownedBy('vendor/uninstalled'))->toBe([]);
});

it('rejects a key another package already registered', function (): void {
    $registry = new BlueprintSubjectRegistry;
    $subject = new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.collection',
        label: 'Collection',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    );

    $registry->register($subject);

    expect(fn (): BlueprintSubjectRegistry => $registry->register($subject))
        ->toThrow(BlueprintSubjectRegistrationException::class, 'already registered');
});

it('rejects a malformed subject key', function (): void {
    $registry = new BlueprintSubjectRegistry;

    expect(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
        key: 'Not Valid',
        label: 'Invalid',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
    )))->toThrow(BlueprintSubjectRegistrationException::class, 'lowercase kebab-case');
});

it('rejects a model that cannot carry a blueprint', function (): void {
    $registry = new BlueprintSubjectRegistry;

    expect(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.invalid',
        label: 'Invalid',
        modelClass: stdClass::class,
        ownerPackage: 'vendor/editorial',
    )))->toThrow(BlueprintSubjectRegistrationException::class, 'must extend');
});

it('rejects a subject with no owning package', function (): void {
    $registry = new BlueprintSubjectRegistry;

    expect(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.unowned',
        label: 'Unowned',
        modelClass: Page::class,
        ownerPackage: '',
    )))->toThrow(BlueprintSubjectRegistrationException::class, 'must name the package');
});

it('rejects a default schema seeder that cannot be run', function (): void {
    $registry = new BlueprintSubjectRegistry;

    expect(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
        key: 'vendor.editorial.seeded',
        label: 'Seeded',
        modelClass: Page::class,
        ownerPackage: 'vendor/editorial',
        defaultSchemaSeeder: 'Vendor\\Editorial\\MissingSeeder',
    )))->toThrow(BlueprintSubjectRegistrationException::class, 'static run method');
});

it('rejects registration once the registry is frozen', function (): void {
    $registry = new BlueprintSubjectRegistry;

    expect($registry->isFrozen())->toBeFalse();

    $registry->freeze();

    expect($registry->isFrozen())->toBeTrue()
        ->and(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
            key: 'vendor.editorial.late',
            label: 'Late',
            modelClass: Page::class,
            ownerPackage: 'vendor/editorial',
        )))->toThrow(BlueprintSubjectRegistrationException::class, 'cannot be registered after boot');
});
