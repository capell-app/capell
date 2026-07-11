<?php

declare(strict_types=1);

use Capell\Admin\Support\Interceptors\Blueprints\Pages\DefaultPageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\HomePageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\NotFoundPageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\SystemPageBlueprintInterceptor;
use Capell\Core\Contracts\ModelInterceptors\BlueprintInterceptorInterface;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\Creator\BlueprintCreator;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class)
    ->group('blueprint');

test('page blueprint creator', function (PageTypeEnum $pageTypeEnum): void {
    $blueprint = resolve(BlueprintCreator::class)->createPageType($pageTypeEnum->value);

    expect($blueprint)->toBeInstanceOf(Blueprint::class)
        ->and($blueprint->key)->toBe($pageTypeEnum->value)
        ->and($blueprint->type)->toBe(BlueprintSubjectEnum::Page);
})->with([PageTypeEnum::cases()]);

// Dataset covering page blueprints and their interceptors + custom admin payload to return from beforeCreate
// Each item: [PageTypeEnum $blueprint, class-string $interceptorClass, array $customAdmin]
dataset('pageBlueprintInterceptors', [
    [PageTypeEnum::Default, DefaultPageBlueprintInterceptor::class, ['admin' => ['marker' => 'default']]],
    [PageTypeEnum::NotFound, NotFoundPageBlueprintInterceptor::class, ['admin' => ['marker' => 'not_found']]],
    [PageTypeEnum::Home, HomePageBlueprintInterceptor::class, ['admin' => ['marker' => 'home']]],
    [PageTypeEnum::System, SystemPageBlueprintInterceptor::class, ['admin' => ['marker' => 'system']]],
]);

// One parameterized test that asserts interceptors are invoked and their admin data is merged
// into the created Blueprint for all supported page blueprints.
test('calls registered interceptors before and after creation for page blueprints', function (PageTypeEnum $pageType, string $interceptorClass, array $customAdmin): void {
    $blueprintModel = Blueprint::class;
    assert(class_exists($interceptorClass));

    CapellCore::registerModelInterceptor(
        $blueprintModel,
        $interceptorClass,
        ['key' => $pageType->value, 'type' => BlueprintSubjectEnum::Page],
    );

    $mock = Mockery::mock($interceptorClass);
    $mock->shouldReceive('beforeCreate')->once()->with([])->andReturn($customAdmin);
    $mock->shouldReceive('afterCreated')->once()->with(
        Mockery::type(Blueprint::class),
        Mockery::on(fn (array $data): bool => isset($data['admin']) && array_intersect_key($data['admin'], $customAdmin['admin']) === $customAdmin['admin']),
    );
    app()->instance($interceptorClass, $mock);

    $blueprint = resolve(BlueprintCreator::class)->createPageType($pageType->value);

    expect($blueprint)->toBeInstanceOf(Blueprint::class)
        ->and($blueprint->key)->toBe($pageType->value)
        ->and($blueprint->type)->toBe(BlueprintSubjectEnum::Page)
        ->and($blueprint->admin)->toMatchArray($customAdmin['admin']);
})->with('pageBlueprintInterceptors');

// Priority order test: lower priority number runs first; both beforeCreate and afterCreated
// should execute in ascending priority order. The last beforeCreate return wins.
test('executes multiple interceptors for the same key in ascending priority', function (): void {
    $blueprintModel = Blueprint::class;
    $key = ['key' => PageTypeEnum::Home->value, 'type' => BlueprintSubjectEnum::Page];

    /** @var class-string<object> $interceptorA */
    $interceptorA = 'AInterceptor';
    /** @var class-string<object> $interceptorB */
    $interceptorB = 'BInterceptor';

    CapellCore::registerModelInterceptor($blueprintModel, $interceptorA, $key, 10);
    CapellCore::registerModelInterceptor($blueprintModel, $interceptorB, $key, 5);

    $calls = [];

    $customA = ['admin' => ['a' => true]];
    $mockA = Mockery::mock(BlueprintInterceptorInterface::class);
    $mockA->shouldReceive('beforeCreate')->once()->with(Mockery::type('array'))->andReturnUsing(function (array $data) use (&$calls, $customA): array {
        $calls[] = 'A-before';

        return $customA;
    });
    $mockA->shouldReceive('afterCreated')->once()->with(
        Mockery::type(Blueprint::class),
        Mockery::on(fn (array $data): bool => isset($data['admin']) && array_intersect_key($data['admin'], $customA['admin']) === $customA['admin']),
    )->andReturnUsing(function () use (&$calls): void {
        $calls[] = 'A-after';
    });
    app()->instance('AInterceptor', $mockA);

    $customB = ['admin' => ['b' => true]];
    $mockB = Mockery::mock(BlueprintInterceptorInterface::class);
    $mockB->shouldReceive('beforeCreate')->once()->with(Mockery::type('array'))->andReturnUsing(function (array $data) use (&$calls, $customB): array {
        $calls[] = 'B-before';

        return $customB;
    });
    // Both afterCreated calls receive the final data from the last beforeCreate (customA)
    $mockB->shouldReceive('afterCreated')->once()->with(
        Mockery::type(Blueprint::class),
        Mockery::on(fn (array $data): bool => isset($data['admin']) && array_intersect_key($data['admin'], $customA['admin']) === $customA['admin']),
    )->andReturnUsing(function () use (&$calls): void {
        $calls[] = 'B-after';
    });
    app()->instance('BInterceptor', $mockB);

    $defaults = [
        'key' => PageTypeEnum::Home->value,
        'type' => BlueprintSubjectEnum::Page,
        'name' => 'home',
        'default' => false,
    ];

    /** @var class-string<Blueprint> $blueprintModel */
    $blueprint = CapellCore::createOrUpdateModel(
        $blueprintModel,
        $key,
        fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
        BlueprintInterceptorInterface::class,
    );

    expect($calls)->toBe(['B-before', 'A-before', 'B-after', 'A-after']);
    $admin = $blueprint->admin;
    expect(is_array($admin) ? $admin : [])->toMatchArray($customA['admin']);
});
