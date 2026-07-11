<?php

declare(strict_types=1);

use Capell\Core\Data\PageVariationData;
use Capell\Core\Exceptions\InvalidPageModelException;
use Capell\Core\Support\CapellCoreManager;
use Capell\Tests\Fixtures\Models\User;

it('registers a page and returns a PageData object', function (): void {
    $manager = new CapellCoreManager;

    $manager->registerPageVariation(new PageVariationData(
        name: 'test',
        model: User::class,
    ));

    $pageData = expectPresent($manager->getPageVariation('test'));

    expect($pageData)->toBeInstanceOf(PageVariationData::class)
        ->and($pageData->name)->toBe('test')
        ->and($pageData->model)->toBe(User::class);
});

it('reports whether a page type is registered', function (): void {
    $manager = new CapellCoreManager;

    $manager->registerPageVariation(new PageVariationData(
        name: 'test',
        model: User::class,
    ));

    expect($manager->hasPageVariation('test'))->toBeTrue()
        ->and($manager->hasPageVariation('missing'))->toBeFalse()
        ->and($manager->hasPageVariation(null))->toBeFalse();
});

it('returns page names and model class strings', function (): void {
    $manager = new CapellCoreManager;

    $manager->registerPageVariation(new PageVariationData(
        name: 'test',
        model: User::class,
    ));

    expect($manager->getPageVariations())->toHaveKey('test')
        ->and($manager->getPageVariationModels())->toContain(User::class);
});

it('throws when registering a non-existent model class', function (): void {
    $manager = new CapellCoreManager;

    expect(function () use ($manager): void {
        $manager->registerPageVariation(new PageVariationData(
            name: 'missing',
            model: 'App\\Models\\DoesNotExist',
        ));
    })->toThrow(InvalidPageModelException::class);
});

it('throws when registering a class that exists but is not an Eloquent model', function (): void {
    $manager = new CapellCoreManager;

    // CapellCoreManager is not an Eloquent Model
    expect(function () use ($manager): void {
        $manager->registerPageVariation(new PageVariationData(
            name: 'not_model',
            model: CapellCoreManager::class,
        ));
    })->toThrow(InvalidPageModelException::class);
});

it('overwrites an existing registration when registering the same type again', function (): void {
    $manager = new CapellCoreManager;

    $manager->registerPageVariation(new PageVariationData(
        name: 'test',
        model: User::class,
        titleAttribute: 'name',
    ));

    $manager->registerPageVariation(new PageVariationData(
        name: 'test',
        model: User::class,
        titleAttribute: 'display_name',
    ));

    $page = expectPresent($manager->getPageVariation('test'));

    expect($page)->toBeInstanceOf(PageVariationData::class)
        ->and($page->titleAttribute)->toBe('display_name');
});

it('returns multiple registered variations and their models in order', function (): void {
    $manager = new CapellCoreManager;

    $manager->registerPageVariation(new PageVariationData(
        name: 'one',
        model: User::class,
    ));

    $manager->registerPageVariation(new PageVariationData(
        name: 'two',
        model: User::class,
    ));

    $types = $manager->getPageVariations();
    $models = $manager->getPageVariationModels();

    expect($types)->toHaveKeys(['one', 'two'])
        ->and($models)->toBeArray()
        ->and($models)->toContain(User::class);
});

it('returns an empty list of models when none registered', function (): void {
    $manager = new CapellCoreManager;

    expect($manager->getPageVariationModels())->toBeArray()->and($manager->getPageVariationModels())->toBeEmpty();
});
