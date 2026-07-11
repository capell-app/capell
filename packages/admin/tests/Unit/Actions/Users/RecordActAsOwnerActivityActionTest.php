<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\RecordActAsOwnerActivityAction;
use Capell\Core\Database\Factories\UserFactory;
use Spatie\Activitylog\Models\Activity;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

uses()
    ->group('activity', 'user');

it('records act as owner activity against the owner account', function (): void {
    $supportUser = UserFactory::new()->createOne(['name' => 'Support admin']);
    $ownerUser = UserFactory::new()->createOne(['name' => 'Site owner']);

    Activity::query()->delete();

    RecordActAsOwnerActivityAction::run(
        supportUser: $supportUser,
        ownerUser: $ownerUser,
        event: RecordActAsOwnerActivityAction::EVENT_STARTED,
    );

    $activity = Activity::query()
        ->where('event', RecordActAsOwnerActivityAction::EVENT_STARTED)
        ->sole();

    expect($activity->log_name)->toBe(RecordActAsOwnerActivityAction::LOG_NAME)
        ->and($activity->description)->toBe(__('capell-admin::activity.act_as_owner_started'))
        ->and($activity->causer?->is($supportUser))->toBeTrue()
        ->and($activity->subject?->is($ownerUser))->toBeTrue()
        ->and(data_get($activity->properties?->toArray() ?? [], 'support_user.id'))->toBe($supportUser->getKey())
        ->and(data_get($activity->properties?->toArray() ?? [], 'owner_user.id'))->toBe($ownerUser->getKey());
});

it('records a stopped act as owner event when the owner account is no longer resolved', function (): void {
    $supportUser = UserFactory::new()->createOne(['name' => 'Support admin']);

    Activity::query()->delete();

    RecordActAsOwnerActivityAction::run(
        supportUser: $supportUser,
        ownerUser: null,
        event: RecordActAsOwnerActivityAction::EVENT_STOPPED,
    );

    $activity = Activity::query()
        ->where('event', RecordActAsOwnerActivityAction::EVENT_STOPPED)
        ->sole();

    expect($activity->description)->toBe(__('capell-admin::activity.act_as_owner_stopped'))
        ->and($activity->causer?->is($supportUser))->toBeTrue()
        ->and($activity->subject)->toBeNull()
        ->and(data_get($activity->properties?->toArray() ?? [], 'owner_user'))->toBeNull();
});

it('records act as owner activity from impersonation events', function (): void {
    $supportUser = UserFactory::new()->createOne(['name' => 'Support admin']);
    $ownerUser = UserFactory::new()->createOne(['name' => 'Site owner']);

    Activity::query()->delete();

    event(new EnterImpersonation($supportUser, $ownerUser));
    event(new LeaveImpersonation($supportUser, $ownerUser));

    expect(Activity::query()->where('event', RecordActAsOwnerActivityAction::EVENT_STARTED)->count())->toBe(1)
        ->and(Activity::query()->where('event', RecordActAsOwnerActivityAction::EVENT_STOPPED)->count())->toBe(1);
});
