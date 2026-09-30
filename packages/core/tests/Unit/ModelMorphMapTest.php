<?php

declare(strict_types=1);

use Capell\Core\Actions\RegisterModelMorphMapAction;
use Capell\Core\Data\PageVariationData;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Diagnostics\Checks\MorphMapCheck;
use Capell\Core\Support\Upgrade\EnsureMorphMapUpgradeStep;
use Capell\Core\Tests\Unit\Fixtures\AccessGate\Models\Event as AccessGateEvent;
use Capell\Core\Tests\Unit\Fixtures\Events\Models\Event as CalendarEvent;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

it('resolves legacy morph types while retaining canonical aliases', function (string $model, string $alias): void {
    /** @var class-string<Model> $model */
    expect(Model::getActualClassNameForMorph($model))->toBe($model)
        ->and(Relation::getMorphedModel($model))->toBe($model)
        ->and((new $model)->getMorphClass())->toBe($alias)
        ->and(Relation::requiresMorphMap())->toBeTrue();
})->with([
    'core page' => [Page::class, 'page'],
    'permission role' => [Role::class, Role::class],
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

it('keeps identical morph registrations idempotent', function (): void {
    $originalMap = Relation::morphMap();

    try {
        Relation::morphMap([], merge: false);
        RegisterModelMorphMapAction::run(['thing' => Page::class]);
        $registeredMap = Relation::morphMap();

        RegisterModelMorphMapAction::run(['thing' => Page::class]);

        expect(Relation::morphMap())->toBe($registeredMap);

        expect(Model::getActualClassNameForMorph(Page::class))->toBe(Page::class)
            ->and((new Page)->getMorphClass())->toBe('thing')
            ->and(Relation::getMorphAlias(Page::class))->toBe('thing');
    } finally {
        Relation::morphMap($originalMap, merge: false);
    }
});

it('preserves last-wins event aliases and reads displaced legacy rows in both boot orders', function (string $firstModel, string $lastModel, string $calendarAlias): void {
    /** @var class-string<Model> $firstModel */
    /** @var class-string<Model> $lastModel */
    $originalMap = Relation::morphMap();

    try {
        foreach ([$firstModel, $lastModel] as $model) {
            Schema::create((new $model)->getTable(), function (Blueprint $table): void {
                $table->id();
            });
        }

        $first = $firstModel::query()->forceCreate(['id' => 1]);
        $last = $lastModel::query()->forceCreate(['id' => 1]);
        $legacyFirst = Activity::query()->create([
            'description' => 'Displaced legacy subject',
            'subject_type' => $firstModel,
            'subject_id' => $first->getKey(),
        ]);
        $legacyLast = Activity::query()->create([
            'description' => 'Canonical legacy subject',
            'subject_type' => $lastModel,
            'subject_id' => $last->getKey(),
        ]);
        $storedAlias = Activity::query()->create([
            'description' => 'Existing event alias',
            'subject_type' => 'event',
            'subject_id' => $last->getKey(),
        ]);

        Relation::morphMap([], merge: false);
        $manager = new CapellCoreManager;
        $manager->registerModels([$firstModel]);
        $manager->registerModels([$lastModel]);

        expect(Relation::requiresMorphMap())->toBeTrue()
            ->and($manager->getModels()['Event'])->toBe($lastModel)
            ->and(Relation::getMorphedModel('event'))->toBe($lastModel)
            ->and(Relation::getMorphedModel($firstModel))->toBe($firstModel)
            ->and(Relation::getMorphedModel($lastModel))->toBe($lastModel)
            ->and(Model::getActualClassNameForMorph($firstModel))->toBe($firstModel)
            ->and(Model::getActualClassNameForMorph($lastModel))->toBe($lastModel)
            ->and($first->getMorphClass())->toBe($firstModel)
            ->and($last->getMorphClass())->toBe('event')
            ->and(Relation::getMorphAlias($firstModel))->toBe($firstModel)
            ->and(Relation::getMorphAlias($lastModel))->toBe('event');

        expect($legacyFirst->fresh(['subject'])?->subject?->is($first))->toBeTrue()
            ->and($legacyLast->fresh(['subject'])?->subject?->is($last))->toBeTrue()
            ->and($storedAlias->fresh(['subject'])?->subject?->is($last))->toBeTrue();

        activity()->performedOn($first)->log('New displaced subject');
        activity()->performedOn($last)->log('New canonical subject');
        $newFirst = Activity::query()->with('subject')->where('description', 'New displaced subject')->sole();
        $newLast = Activity::query()->with('subject')->where('description', 'New canonical subject')->sole();

        expect($newFirst->subject_type)->toBe($firstModel)
            ->and($newLast->subject_type)->toBe('event')
            ->and($newFirst->subject?->is($first))->toBeTrue()
            ->and($newLast->subject?->is($last))->toBeTrue();

        $manager->registerPageVariation(new PageVariationData(name: 'event', model: CalendarEvent::class));
        $manager->ensurePageVariationMorphAliases();

        $calendar = $first instanceof CalendarEvent ? $first : $last;
        activity()->performedOn($calendar)->log('Event page variation subject');
        $pageVariationActivity = Activity::query()->with('subject')->where('description', 'Event page variation subject')->sole();

        expect(Relation::getMorphedModel('event'))->toBe($lastModel)
            ->and($calendar->getMorphClass())->toBe($calendarAlias)
            ->and($pageVariationActivity->subject_type)->toBe($calendarAlias)
            ->and($pageVariationActivity->subject?->is($calendar))->toBeTrue()
            ->and($legacyFirst->fresh(['subject'])?->subject?->is($first))->toBeTrue()
            ->and($legacyLast->fresh(['subject'])?->subject?->is($last))->toBeTrue();
    } finally {
        Relation::morphMap($originalMap, merge: false);
    }
})->with([
    'access-gate then events' => [AccessGateEvent::class, CalendarEvent::class, 'event'],
    'events then access-gate' => [CalendarEvent::class, AccessGateEvent::class, 'capell_core_tests_unit_fixtures_events_event'],
]);

it('retains a displaced legacy type when its alias was registered outside Core', function (): void {
    $originalMap = Relation::morphMap();

    try {
        Relation::morphMap(['event' => AccessGateEvent::class], merge: false);
        new CapellCoreManager()->registerModels([CalendarEvent::class]);

        expect(Relation::getMorphedModel('event'))->toBe(CalendarEvent::class)
            ->and(Relation::getMorphedModel(AccessGateEvent::class))->toBe(AccessGateEvent::class)
            ->and((new AccessGateEvent)->getMorphClass())->toBe(AccessGateEvent::class)
            ->and((new CalendarEvent)->getMorphClass())->toBe('event');
    } finally {
        Relation::morphMap($originalMap, merge: false);
    }
});

it('keeps legacy class keys bound to their models when another registration uses that key', function (): void {
    $originalMap = Relation::morphMap();

    try {
        Relation::morphMap([], merge: false);
        RegisterModelMorphMapAction::run(['thing' => Page::class]);
        RegisterModelMorphMapAction::run([
            'site' => Site::class,
            Page::class => Site::class,
        ]);

        expect(Relation::getMorphedModel(Page::class))->toBe(Page::class)
            ->and(Relation::getMorphedModel(Site::class))->toBe(Site::class)
            ->and((new Page)->getMorphClass())->toBe('thing')
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
