<?php

declare(strict_types=1);

use Capell\Core\Concerns\HasModelInterceptors;
use Capell\Tests\Fixtures\Models\InMemoryUserModel;
use Capell\Tests\Support\Fakes\DummyIntegrationInterceptor;

beforeEach(function (): void {
    InMemoryUserModel::resetRecords();
});

it('createOrUpdateModel applies interceptors and returns object without DB', function (): void {
    $trait = new class
    {
        use HasModelInterceptors;
    };

    $modelClass = InMemoryUserModel::class;
    $keyConditions = ['id' => 1];
    $interceptorClass = DummyIntegrationInterceptor::class;
    $interceptorInterface = DummyIntegrationInterceptor::class;

    $trait->registerModelInterceptor($modelClass, $interceptorClass, $keyConditions);

    $persist = (fn (array $data): array => array_merge(['id' => 1], $data));

    $createdEntity = $trait->createOrUpdateModel($modelClass, $keyConditions, $persist, $interceptorInterface);
    assert($createdEntity instanceof InMemoryUserModel);

    expect($createdEntity)->toBeInstanceOf(InMemoryUserModel::class)
        ->and($createdEntity->attributes['updated'])->toBeTrue()
        ->and($createdEntity->intercepted)->toBeTrue();

    $updatedEntity = $trait->createOrUpdateModel($modelClass, $keyConditions, $persist, $interceptorInterface);
    assert($updatedEntity instanceof InMemoryUserModel);

    expect($updatedEntity)->toBeInstanceOf(InMemoryUserModel::class)
        ->and($updatedEntity->attributes['updated'])->toBeTrue()
        ->and($updatedEntity->intercepted)->toBeTrue();
    expect(InMemoryUserModel::$records)->toHaveCount(1);
    expect($updatedEntity)->toBe($createdEntity);
});
