<?php

declare(strict_types=1);

use Capell\Admin\Actions\Activity\DeleteActivityLogAction;
use Capell\Admin\Actions\Activity\RevertActivityAction;
use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Tests\Fixtures\Autoload\CapturingActivityRevertHandlerForTest;
use Capell\Admin\Tests\Fixtures\Autoload\PermissiveActivityRevertHandlerForTest;
use Capell\Core\Models\Language;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

it('restores fillable attributes from the old activity values', function (): void {
    test()->actingAs(createActivityLogPermittedUser(CapellPermission::RevertActivityLog));

    $language = Language::factory()->createOne(['name' => 'French']);

    $activity = loggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language'));

    $result = RevertActivityAction::run($activity);

    expect($result->successful)->toBeTrue()
        ->and($language->refresh()->name)->toBe('Francais');
});

it('only restores selected old activity values', function (): void {
    test()->actingAs(createActivityLogPermittedUser(CapellPermission::RevertActivityLog));

    $language = Language::factory()->createOne([
        'name' => 'French',
        'code' => 'fr',
    ]);

    $activity = loggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais', 'code' => 'fra'],
            'attributes' => ['name' => 'French', 'code' => 'fr'],
        ])
        ->log('updated language'));

    $result = RevertActivityAction::run($activity, ['name']);

    expect($result->successful)->toBeTrue()
        ->and($language->refresh()->name)->toBe('Francais')
        ->and($language->code)->toBe('fr');
});

it('filters selected paths before resolving package revert handlers', function (): void {
    CapturingActivityRevertHandlerForTest::$selection = null;
    app()->tag([CapturingActivityRevertHandlerForTest::class], ActivityRevertHandler::TAG);
    test()->actingAs(createActivityLogPermittedUser(CapellPermission::RevertActivityLog));

    $activity = loggedActivity(activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language'));

    $result = RevertActivityAction::run($activity, ['name', 'id']);
    $selection = capturedActivityRevertSelection();

    assert($selection instanceof ActivityRevertSelectionData);

    expect($result->successful)->toBeTrue()
        ->and($result->skippedFields)->toBe(['missing_old_value' => ['id']])
        ->and($selection->selectedPaths)->toBe(['name']);
});

it('denies revert when the actor does not have the activity log revert permission', function (): void {
    test()->actingAs(test()->createUser());

    $language = Language::factory()->createOne(['name' => 'French']);

    $activity = loggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language'));

    $result = RevertActivityAction::run($activity, ['name']);

    expect($result->successful)->toBeFalse()
        ->and($result->skippedFields)->toBe(['unauthorized' => ['name']])
        ->and($language->refresh()->name)->toBe('French');
});

it('denies revert before resolving package handlers', function (): void {
    app()->tag([PermissiveActivityRevertHandlerForTest::class], ActivityRevertHandler::TAG);
    test()->actingAs(test()->createUser());

    $language = Language::factory()->createOne(['name' => 'French']);

    $activity = loggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language'));

    $result = RevertActivityAction::run($activity, ['name']);

    expect($result->successful)->toBeFalse()
        ->and($language->refresh()->name)->toBe('French');
});

it('does not overwrite a field when the current value no longer matches the activity after value', function (): void {
    test()->actingAs(createActivityLogPermittedUser(CapellPermission::RevertActivityLog));

    $language = Language::factory()->createOne(['name' => 'French']);

    $activity = loggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language'));

    $language->update(['name' => 'French updated again']);

    $result = RevertActivityAction::run($activity, ['name']);

    expect($result->successful)->toBeFalse()
        ->and($result->skippedFields)->toBe(['stale_value' => ['name']])
        ->and($language->refresh()->name)->toBe('French updated again');
});

it('does not directly revert nested activity paths', function (): void {
    test()->actingAs(createActivityLogPermittedUser(CapellPermission::RevertActivityLog));

    $language = Language::factory()->createOne(['name' => 'French']);

    $activity = loggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['meta.title' => 'Francais'],
            'attributes' => ['meta.title' => 'French'],
        ])
        ->log('updated language'));

    $result = RevertActivityAction::run($activity, ['meta.title']);

    expect($result->successful)->toBeFalse()
        ->and($result->skippedFields)->toBe(['nested_path' => ['meta.title']])
        ->and($language->refresh()->name)->toBe('French');
});

it('does not directly mutate workspace stamped activity entries', function (): void {
    test()->actingAs(createActivityLogPermittedUser(CapellPermission::RevertActivityLog));

    $language = Language::factory()->createOne(['name' => 'French']);

    $activity = loggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'workspace_id' => 123,
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language'));

    $result = RevertActivityAction::run($activity, ['name']);

    expect($result->successful)->toBeFalse()
        ->and($result->skippedFields)->toBe(['workspace_context' => ['name']])
        ->and($language->refresh()->name)->toBe('French');
});

it('compares stale casted values after applying model casts', function (): void {
    test()->actingAs(createActivityLogPermittedUser(CapellPermission::RevertActivityLog));

    $language = Language::factory()->createOne(['status' => true]);

    $activity = loggedActivity(activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['status' => false],
            'attributes' => ['status' => '1'],
        ])
        ->log('updated language'));

    $result = RevertActivityAction::run($activity, ['status']);

    expect($result->successful)->toBeTrue()
        ->and($language->refresh()->status)->toBeFalse();
});

it('deletes activity log entries only with delete permission', function (): void {
    $activity = loggedActivity(activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties(['old' => ['name' => 'Francais'], 'attributes' => ['name' => 'French']])
        ->log('updated language'));

    test()->actingAs(test()->createUser());

    DeleteActivityLogAction::run($activity);
})->throws(AuthorizationException::class);

it('deletes activity log entries for permitted actors', function (): void {
    test()->actingAs(createActivityLogPermittedUser(CapellPermission::DeleteActivityLog));

    $activity = loggedActivity(activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties(['old' => ['name' => 'Francais'], 'attributes' => ['name' => 'French']])
        ->log('updated language'));

    expect(DeleteActivityLogAction::run($activity))->toBeTrue()
        ->and(Activity::query()->whereKey($activity->getKey())->exists())->toBeFalse();

    $deletionAudit = Activity::query()
        ->where('description', 'deleted activity log entry')
        ->first();

    $deletionAudit = expectPresent($deletionAudit);

    $properties = expectPresent($deletionAudit->properties);

    expect($deletionAudit)->toBeInstanceOf(Activity::class)
        ->and($properties->get('deleted_activity_id'))->toBe($activity->getKey())
        ->and($properties->get('deleted_activity_subject_type'))->toBe($activity->subject_type);
});

function createActivityLogPermittedUser(CapellPermission $permission): object
{
    Permission::findOrCreate($permission->name());

    return test()->createUserWithPermission($permission->name());
}

function loggedActivity(mixed $activity): Activity
{
    assert($activity instanceof Activity);

    return $activity;
}

function capturedActivityRevertSelection(): ?ActivityRevertSelectionData
{
    return CapturingActivityRevertHandlerForTest::$selection;
}
