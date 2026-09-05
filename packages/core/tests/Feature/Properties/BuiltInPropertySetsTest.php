<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\SyncBuiltInPropertySetsAction;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;

it('creates the three core built-in property sets with their definitions', function (): void {
    SyncBuiltInPropertySetsAction::run();

    expect(PropertySet::query()->count())->toBe(3)
        ->and(PropertySet::query()->where('key', 'commerce.product')->exists())->toBeTrue()
        ->and(PropertySet::query()->where('key', 'events.event')->exists())->toBeTrue()
        ->and(PropertySet::query()->where('key', 'content.article')->exists())->toBeTrue();

    $product = PropertySet::query()->where('key', 'commerce.product')->firstOrFail();
    $price = $product->definitions()->where('key', 'price')->firstOrFail();

    expect($price->type)->toBe(PropertyType::Money)
        ->and($price->semantic)->toBe('schema:price')
        ->and($price->requirement)->toBe(PropertyRequirement::Contract)
        ->and($price->locked)->toBeTrue();
});

it('is idempotent: syncing twice creates no duplicates', function (): void {
    SyncBuiltInPropertySetsAction::run();
    SyncBuiltInPropertySetsAction::run();

    expect(PropertySet::query()->count())->toBe(3)
        ->and(PropertyDefinition::query()->count())->toBe(4 + 3 + 3);
});

it('restores a deleted built-in definition on the next sync', function (): void {
    SyncBuiltInPropertySetsAction::run();

    $product = PropertySet::query()->where('key', 'commerce.product')->firstOrFail();
    $product->definitions()->where('key', 'sku')->delete();

    expect($product->definitions()->where('key', 'sku')->exists())->toBeFalse();

    SyncBuiltInPropertySetsAction::run();

    expect($product->definitions()->where('key', 'sku')->exists())->toBeTrue();
});

it('clamps a publish-required definition to contract when it arrives on an existing set version bump', function (): void {
    $firstInstall = [
        'test.widget' => [
            'name' => 'Widget',
            'definitions' => [
                [
                    'key' => 'name',
                    'type' => PropertyType::Text,
                    'semantic' => null,
                    'requirement' => PropertyRequirement::None,
                    'locked' => false,
                    'description' => 'Widget name',
                    'unit_config' => null,
                ],
            ],
        ],
    ];

    SyncBuiltInPropertySetsAction::run($firstInstall);

    $versionBump = [
        'test.widget' => [
            'name' => 'Widget',
            'definitions' => [
                ...$firstInstall['test.widget']['definitions'],
                [
                    'key' => 'serial',
                    'type' => PropertyType::Text,
                    'semantic' => null,
                    'requirement' => PropertyRequirement::Publish,
                    'locked' => false,
                    'description' => 'Widget serial number',
                    'unit_config' => null,
                ],
            ],
        ],
    ];

    SyncBuiltInPropertySetsAction::run($versionBump);

    $set = PropertySet::query()->where('key', 'test.widget')->firstOrFail();
    $serial = $set->definitions()->where('key', 'serial')->firstOrFail();

    expect($serial->requirement)->toBe(PropertyRequirement::Contract);
});

it('keeps a publish-required definition as-is on a genuine first install', function (): void {
    $firstInstall = [
        'test.strict' => [
            'name' => 'Strict',
            'definitions' => [
                [
                    'key' => 'mandatory',
                    'type' => PropertyType::Text,
                    'semantic' => null,
                    'requirement' => PropertyRequirement::Publish,
                    'locked' => false,
                    'description' => 'Mandatory field',
                    'unit_config' => null,
                ],
            ],
        ],
    ];

    SyncBuiltInPropertySetsAction::run($firstInstall);

    $set = PropertySet::query()->where('key', 'test.strict')->firstOrFail();
    $mandatory = $set->definitions()->where('key', 'mandatory')->firstOrFail();

    expect($mandatory->requirement)->toBe(PropertyRequirement::Publish);
});
