<?php

declare(strict_types=1);

use Capell\Core\Data\PageVariationData;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Diagnostics\Checks\MorphMapCheck;
use Capell\Core\Support\Upgrade\EnsureMorphMapUpgradeStep;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\Models\Role;

it('resolves legacy morph types while retaining canonical aliases', function (string $model, string $alias): void {
    /** @var class-string<Model> $model */
    expect(Model::getActualClassNameForMorph($model))->toBe($model)
        ->and(Relation::getMorphedModel($model))->toBe($model)
        ->and((new $model)->getMorphClass())->toBe($alias)
        ->and(Relation::requiresMorphMap())->toBeTrue();
})->with([
    'core page' => [Page::class, 'page'],
    'permission role' => [Role::class, 'capell_role'],
    'fixture user' => [User::class, 'user'],
]);

it('registers legacy types for every core model', function (): void {
    foreach (CapellCore::getModels() as $model) {
        expect(Model::getActualClassNameForMorph($model))->toBe($model)
            ->and(Relation::getMorphedModel($model))->toBe($model)
            ->and((new $model)->getMorphClass())->not->toBe($model);
    }
});

it('registers legacy types for models contributed after provider boot', function (): void {
    $originalMap = Relation::morphMap();

    try {
        Relation::morphMap([], merge: false);
        $manager = new CapellCoreManager;
        $manager->registerModels([Site::class]);

        expect(Model::getActualClassNameForMorph(Site::class))->toBe(Site::class)
            ->and((new Site)->getMorphClass())->toBe('site');
    } finally {
        Relation::morphMap($originalMap, merge: false);
    }
});

it('registers legacy page variation types without replacing a conflicting alias', function (): void {
    $originalMap = Relation::morphMap();

    try {
        Relation::morphMap(['user' => Page::class], merge: false);
        $manager = new CapellCoreManager;
        $manager->registerPageVariation(new PageVariationData(name: 'test-user', model: User::class));
        $manager->ensurePageVariationMorphAliases();

        expect(Model::getActualClassNameForMorph(User::class))->toBe(User::class)
            ->and(Relation::morphMap()['user'])->toBe(Page::class)
            ->and((new User)->getMorphClass())->toBe('capell_tests_fixtures_user');
    } finally {
        Relation::morphMap($originalMap, merge: false);
    }
});

it('keeps unmapped model writes forbidden', function (): void {
    $unknown = new class extends Model
    {
        /** @use HasFactory<Factory<Model>> */
        use HasFactory;
    };

    expect(fn (): string => $unknown->getMorphClass())->toThrow(ClassMorphViolationException::class);
});

it('repairs legacy keys when the canonical core alias already exists', function (): void {
    $originalMap = Relation::morphMap();

    try {
        Relation::morphMap(['page' => Page::class], merge: false);
        $step = new EnsureMorphMapUpgradeStep;
        $step->run(new UpgradeContext([], [], []));

        expect(Model::getActualClassNameForMorph(Page::class))->toBe(Page::class)
            ->and(Relation::getMorphedModel(Page::class))->toBe(Page::class)
            ->and((new Page)->getMorphClass())->toBe('page');
    } finally {
        Relation::morphMap($originalMap, merge: false);
    }
});

it('diagnoses missing legacy keys even when canonical aliases are present', function (): void {
    $originalMap = Relation::morphMap();

    try {
        $canonical = array_filter($originalMap, fn (string $model, string $alias): bool => $alias !== $model, ARRAY_FILTER_USE_BOTH);
        Relation::morphMap($canonical, merge: false);
        $check = new MorphMapCheck;

        expect($check->check()->passed)->toBeFalse()
            ->and($check->check()->message)->toContain(Page::class);
    } finally {
        Relation::morphMap($originalMap, merge: false);
    }
});
