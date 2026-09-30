<?php

declare(strict_types=1);

use Capell\Admin\Actions\Shield\LogRolePermissionChangesAction;
use Capell\Admin\Data\Shield\RolePermissionChangeSetData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

it('finds existing and newly logged role activity using the stored class name', function (): void {
    $role = Role::query()->create([
        'name' => 'content_manager',
        'guard_name' => 'web',
    ]);
    $existingActivity = Activity::query()->create([
        'description' => 'Existing role permission change',
        'subject_type' => Role::class,
        'subject_id' => $role->getKey(),
    ]);

    LogRolePermissionChangesAction::run($role, new RolePermissionChangeSetData(
        before: [],
        after: ['View:Page'],
        added: ['View:Page'],
        removed: [],
        unchanged: [],
    ));

    $permissionActivity = Activity::query()
        ->where('description', '1 added, 0 removed')
        ->sole();

    $standardActivity = activity()
        ->performedOn($role)
        ->log('Standard role activity');

    expect($standardActivity)->toBeInstanceOf(Activity::class);
    assert($standardActivity instanceof Activity);

    $activities = Activity::query()->with('subject')->forSubject($role)->orderBy('id')->get();

    expect($activities->modelKeys())->toBe([
        $existingActivity->getKey(),
        $permissionActivity->getKey(),
        $standardActivity->getKey(),
    ])
        ->and(Relation::requiresMorphMap())->toBeTrue()
        ->and(Relation::getMorphedModel(Role::class))->toBe(Role::class)
        ->and(Model::getActualClassNameForMorph(Role::class))->toBe(Role::class)
        ->and($role->getMorphClass())->toBe(Role::class)
        ->and(Relation::getMorphAlias(Role::class))->toBe(Role::class);

    foreach ($activities as $loggedActivity) {
        expect($loggedActivity->subject_type)->toBe(Role::class)
            ->and($loggedActivity->subject?->is($role))->toBeTrue();
    }
});
