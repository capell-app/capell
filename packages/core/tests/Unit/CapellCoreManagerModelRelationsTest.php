<?php

declare(strict_types=1);

use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Tests\Unit\Fixtures\TestEnum;

describe('HasModelRelations trait', function (): void {
    it('registers and retrieves string relations', function (): void {
        $manager = new CapellCoreManager;
        $modelKey = 'TestModel';
        $relationA = 'relationA';
        $relationB = 'relationB';

        expect($manager::getModelRelations($modelKey))->toBe([]);

        $manager::registerModelRelations($modelKey, $relationA);
        expect($manager::getModelRelations($modelKey))->toBe([$relationA]);

        $manager::registerModelRelations($modelKey, $relationB);
        expect($manager::getModelRelations($modelKey))->toBe([$relationA, $relationB]);
    });

    it('registers and retrieves closure relations', function (): void {
        $manager = new CapellCoreManager;
        $modelKey = 'TestModelClosure';
        $closure = fn (): string => 'dynamicRelation';

        expect($manager::getModelRelations($modelKey))->toBe([]);

        $manager::registerModelRelations($modelKey, $closure);
        $relations = $manager::getModelRelations($modelKey);
        expect($relations)->toHaveCount(1)
            ->and($relations[0])->toBeInstanceOf(Closure::class);
    });

    it('registers and retrieves array relations', function (): void {
        $manager = new CapellCoreManager;
        $modelKey = 'TestModelArray';
        $relations = ['rel1', 'rel2'];

        $manager::registerModelRelations($modelKey, $relations);
        expect($manager::getModelRelations($modelKey))->toBe($relations);
    });

    it('registers relations for BackedEnum key', function (): void {
        $manager = new CapellCoreManager;
        $relation = 'enumRelation';

        $manager::registerModelRelations(TestEnum::Foo, $relation);
        // Should be stored under key 'foo' (BackedEnum value)
        expect($manager::getModelRelations(TestEnum::Foo))->toBe([$relation])
            ->and($manager::getModelRelations('foo'))->toBe([$relation]);
    });

    it('does not duplicate string or closure relations', function (): void {
        $manager = new CapellCoreManager;
        $modelKey = 'NoDupes';
        $relation = 'uniqueRelation';
        $closure = fn (): string => 'closure';

        $manager::registerModelRelations($modelKey, [$relation, $relation, $closure, $closure]);
        $relations = $manager::getModelRelations($modelKey);
        $strings = array_filter($relations, is_string(...));
        $closures = array_filter($relations, fn (mixed $r): bool => $r instanceof Closure);
        expect($strings)->toBe([$relation])
            ->and($closures)->toHaveCount(1);
    });
});
