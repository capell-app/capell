<?php

declare(strict_types=1);

use Capell\Core\Concerns\HasModelInterceptors;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Tests\Integration\Fixtures\IntegrationTestInterceptor;
use Capell\Core\Tests\Integration\Fixtures\IntegrationTestModel;
use Capell\Tests\Fixtures\Models\InMemoryUserModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('integration_test_models', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('updated')->nullable();
        $table->string('type')->nullable();
        $table->string('key')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('integration_test_models');
});

it('createOrUpdateModel creates new model with merged data and interceptors', function (): void {
    $trait = new class
    {
        use HasModelInterceptors;
    };

    $model = IntegrationTestModel::class;
    $key = ['key' => 'default', 'type' => 'site'];
    $interceptorClass = IntegrationTestInterceptor::class;
    $interceptorInterface = IntegrationTestInterceptor::class;
    $defaults = ['name' => 'test', 'type' => 'site', 'key' => 'default'];

    $trait->registerModelInterceptor($model, $interceptorClass, $key);

    $persist = fn (array $data): array => array_merge($defaults, $data);

    $entity = $trait->createOrUpdateModel($model, $key, $persist, $interceptorInterface);

    expect($entity)->toBeInstanceOf(IntegrationTestModel::class)
        ->and($entity->updated)->toBe('yes')
        ->and($entity->intercepted)->toBeTrue()
        ->and($entity->name)->toBe('test')
        ->and($entity->type)->toBe('site')
        ->and($entity->key)->toBe('default');
});

it('createOrUpdateModel updates existing model with new merged data and interceptors', function (): void {
    $trait = new class
    {
        use HasModelInterceptors;
    };

    $model = IntegrationTestModel::class;
    $key = ['key' => 'default', 'type' => 'site'];
    $interceptorClass = IntegrationTestInterceptor::class;
    $interceptorInterface = IntegrationTestInterceptor::class;
    $defaults = ['name' => 'test', 'type' => 'site', 'key' => 'default'];

    $trait->registerModelInterceptor($model, $interceptorClass, $key);

    // Create initial model
    $initial = IntegrationTestModel::query()->create(['name' => 'initial', 'type' => 'site', 'key' => 'default']);

    $persist = fn (array $data): array => array_merge($defaults, $data);

    $entity = $trait->createOrUpdateModel($model, $key, $persist, $interceptorInterface);

    expect($entity)->toBeInstanceOf(IntegrationTestModel::class)
        ->and($entity->updated)->toBe('yes')
        ->and($entity->intercepted)->toBeTrue()
        ->and($entity->name)->toBe('test')
        ->and($entity->type)->toBe('site')
        ->and($entity->key)->toBe('default');
});

it('createOrUpdateModel does not duplicate model for same key/type', function (): void {
    $trait = new class
    {
        use HasModelInterceptors;
    };

    $model = IntegrationTestModel::class;
    $key = ['key' => 'default', 'type' => 'site'];
    $interceptorClass = IntegrationTestInterceptor::class;
    $interceptorInterface = IntegrationTestInterceptor::class;
    $defaults = ['name' => 'test', 'type' => 'site', 'key' => 'default'];

    $trait->registerModelInterceptor($model, $interceptorClass, $key);

    $persist = fn (array $data): array => array_merge($defaults, $data);

    $entity1 = $trait->createOrUpdateModel($model, $key, $persist, $interceptorInterface);
    $entity2 = $trait->createOrUpdateModel($model, $key, $persist, $interceptorInterface);

    expect($entity1->id)->toBe($entity2->id)
        ->and(IntegrationTestModel::query()->where($key)->count())->toBe(1);
});

it('creates models through ordered interceptors and keeps the registry maintainable', function (): void {
    InMemoryUserModel::resetRecords();

    $trait = new class
    {
        use HasModelInterceptors;
    };

    $model = InMemoryUserModel::class;
    $key = ['key' => BlueprintSubjectEnum::Page, 'type' => 'landing'];
    $lookup = ['key' => BlueprintSubjectEnum::Page->value, 'type' => 'landing'];

    $trait->registerModelInterceptor($model, SecondCreateModelInterceptor::class, $key, priority: 10);
    $trait->registerModelInterceptor($model, FirstCreateModelInterceptor::class, $key, priority: 20);
    $trait->registerModelInterceptor($model, FirstCreateModelInterceptor::class, $key, priority: 0);

    expect($trait->getInterceptorsForModelAndKey($model, $lookup))->toBe([
        FirstCreateModelInterceptor::class,
        SecondCreateModelInterceptor::class,
    ]);

    $entity = $trait->createModel(
        $model,
        $lookup,
        fn (array $data): InMemoryUserModel => new InMemoryUserModel(array_merge(['id' => 1], $data)),
        CreateModelInterceptorContract::class,
    );
    assert($entity instanceof InMemoryUserModel);

    expect($entity->attributes['steps'])->toBe(['first', 'second'])
        ->and($entity->attributes['after'])->toBe(['first', 'second'])
        ->and($trait->getInterceptorsForModelAndKey($model, ['key' => 'page', 'type' => 'article']))->toBe([]);

    $trait->unregisterModelInterceptor($model, SecondCreateModelInterceptor::class, $key);

    expect($trait->getInterceptorsForModelAndKey($model, $lookup))->toBe([
        FirstCreateModelInterceptor::class,
    ]);

    $trait->replaceModelInterceptor($model, FirstCreateModelInterceptor::class, ReplacementCreateModelInterceptor::class, $key);

    expect($trait->getInterceptorsForModelAndKey($model, $lookup))->toBe([
        ReplacementCreateModelInterceptor::class,
    ]);
});

it('merges nested interceptor payloads and fails fast on invalid lifecycle contracts', function (): void {
    $trait = new class
    {
        use HasModelInterceptors;
    };

    expect($trait->mergeModelInterceptorData(
        [
            'name' => 'Default',
            'meta' => [
                'colors' => ['primary' => '#ffffff'],
                'layout' => 'default',
            ],
        ],
        [
            'name' => 'Override',
            'meta' => [
                'colors' => ['secondary' => '#000000'],
            ],
        ],
    ))->toBe([
        'name' => 'Override',
        'meta' => [
            'colors' => [
                'primary' => '#ffffff',
                'secondary' => '#000000',
            ],
            'layout' => 'default',
        ],
    ]);

    $trait->registerModelInterceptor(InMemoryUserModel::class, stdClass::class, 'page');

    expect(fn (): object => $trait->createModel(
        InMemoryUserModel::class,
        'page',
        fn (array $data): InMemoryUserModel => new InMemoryUserModel($data),
        CreateModelInterceptorContract::class,
    ))->toThrow(InvalidArgumentException::class, 'must implement');

    $missingMethodTrait = new class
    {
        use HasModelInterceptors;
    };
    $missingMethodTrait->registerModelInterceptor(InMemoryUserModel::class, stdClass::class, 'page');

    expect(fn (): object => $missingMethodTrait->createModel(
        InMemoryUserModel::class,
        'page',
        fn (array $data): InMemoryUserModel => new InMemoryUserModel($data),
        stdClass::class,
    ))->toThrow(InvalidArgumentException::class, 'beforeCreate is not callable');

    $badReturnTrait = new class
    {
        use HasModelInterceptors;
    };
    $badReturnTrait->registerModelInterceptor(InMemoryUserModel::class, BadReturnCreateModelInterceptor::class, 'bad-return');

    expect(fn (): object => $badReturnTrait->createModel(
        InMemoryUserModel::class,
        'bad-return',
        fn (array $data): InMemoryUserModel => new InMemoryUserModel($data),
        BadReturnCreateModelInterceptor::class,
    ))->toThrow(InvalidArgumentException::class, 'beforeCreate must return an array');

    expect(fn (): object => $trait->createModel(
        InMemoryUserModel::class,
        'missing',
        fn (array $data): stdClass => new stdClass,
        CreateModelInterceptorContract::class,
    ))->toThrow(InvalidArgumentException::class, 'must return ' . InMemoryUserModel::class);
});

interface CreateModelInterceptorContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array;

    /** @param array<string, mixed> $data */
    public function afterCreated(object $entity, array $data): void;
}

final class FirstCreateModelInterceptor implements CreateModelInterceptorContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array
    {
        $data['steps'][] = 'first';

        return $data;
    }

    public function afterCreated(object $entity, array $data): void
    {
        if ($entity instanceof InMemoryUserModel) {
            $entity->attributes['after'][] = 'first';
        }
    }
}

final class SecondCreateModelInterceptor implements CreateModelInterceptorContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array
    {
        $data['steps'][] = 'second';

        return $data;
    }

    public function afterCreated(object $entity, array $data): void
    {
        if ($entity instanceof InMemoryUserModel) {
            $entity->attributes['after'][] = 'second';
        }
    }
}

final class ReplacementCreateModelInterceptor implements CreateModelInterceptorContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array
    {
        $data['steps'][] = 'replacement';

        return $data;
    }

    public function afterCreated(object $entity, array $data): void {}
}

final class BadReturnCreateModelInterceptor
{
    public function beforeCreate(): string
    {
        return 'invalid';
    }
}
