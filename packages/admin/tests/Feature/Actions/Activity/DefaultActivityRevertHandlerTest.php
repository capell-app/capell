<?php

declare(strict_types=1);

use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Support\Activity\DefaultActivityRevertHandler;
use Capell\Core\Models\Language;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

it('is the fallback activity revert handler for any selection', function (): void {
    $handler = new DefaultActivityRevertHandler;

    expect($handler->supports(defaultActivityRevertSelection(activityId: 1, selectedPaths: ['name'])))->toBeTrue()
        ->and($handler->priority())->toBe(0);
});

it('returns contextual failures before mutating activity subjects', function (): void {
    $handler = new DefaultActivityRevertHandler;
    $language = Language::factory()->createOne(['name' => 'French']);

    $activity = defaultLoggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language'));

    test()->actingAs(test()->createUser());

    expect($handler->revert(defaultActivityRevertSelection($activity->getKey(), ['name'])))
        ->successful->toBeFalse()
        ->skippedFields->toBe(['unauthorized' => ['name']]);

    test()->actingAs(defaultActivityRevertPermittedUser());

    expect($handler->revert(defaultActivityRevertSelection(999999, ['name'])))
        ->successful->toBeFalse()
        ->skippedFields->toBe(['missing_activity' => ['name']]);

    expect($handler->revert(defaultActivityRevertSelection($activity->getKey(), ['name'], workspaceId: 42)))
        ->successful->toBeFalse()
        ->workspaceId->toBe(42)
        ->skippedFields->toBe(['workspace_context' => ['name']]);

    $deletedActivity = defaultLoggedActivity(activity()
        ->performedOn($language)
        ->event('deleted')
        ->withProperties(['old' => ['name' => 'French']])
        ->log('deleted language'));

    expect($handler->revert(defaultActivityRevertSelection($deletedActivity->getKey(), ['name'])))
        ->successful->toBeFalse()
        ->skippedFields->toBe(['unsupported_event' => ['name']]);

    $missingSubjectActivity = defaultLoggedActivity(activity()
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated unknown subject'));

    expect($handler->revert(defaultActivityRevertSelection($missingSubjectActivity->getKey(), ['name'])))
        ->successful->toBeFalse()
        ->skippedFields->toBe(['missing_subject' => ['name']]);
});

it('skips unsafe fields and treats already-matching values as a successful no-op', function (): void {
    test()->actingAs(defaultActivityRevertPermittedUser());

    $language = Language::factory()->createOne(['name' => 'French']);
    $activity = defaultLoggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => [
                'name' => 'French',
                'meta.title' => 'Francais',
                'id' => $language->getKey(),
            ],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language'));

    $result = (new DefaultActivityRevertHandler)->revert(defaultActivityRevertSelection(
        activityId: $activity->getKey(),
        selectedPaths: ['missing', 'meta.title', 'id', 'name'],
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->skippedFields)->toBe([
            'missing_old_value' => ['missing'],
            'nested_path' => ['meta.title'],
            'not_fillable' => ['id'],
        ])
        ->and($language->refresh()->name)->toBe('French');
});

/** @param list<string> $selectedPaths */
function defaultActivityRevertSelection(int $activityId, array $selectedPaths, ?int $workspaceId = null): ActivityRevertSelectionData
{
    return new ActivityRevertSelectionData(
        activityId: $activityId,
        selectedPaths: $selectedPaths,
        beforeValues: [],
        actorId: auth()->id(),
        subjectMorphType: null,
        subjectClass: null,
        subjectId: null,
        stableIdentifier: null,
        workspaceId: $workspaceId,
    );
}

function defaultActivityRevertPermittedUser(): object
{
    Permission::findOrCreate(CapellPermission::RevertActivityLog->name());

    return test()->createUserWithPermission(CapellPermission::RevertActivityLog->name());
}

function defaultLoggedActivity(mixed $activity): Activity
{
    assert($activity instanceof Activity);

    return $activity;
}
