<?php

declare(strict_types=1);

use Capell\Admin\Actions\Activity\RevertActivityAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Support\Activity\DefaultActivityChangeSetBuilder;
use Capell\Admin\Tests\Fixtures\Activity\GlobalAuditUser;
use Capell\Core\Models\Language;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

it('presents and reverts modern tracked values while preserving custom properties', function (): void {
    Permission::findOrCreate(CapellPermission::RevertActivityLog->name());
    test()->actingAs(GlobalAuditUser::fromUser(test()->createUserWithPermission(CapellPermission::RevertActivityLog->name())));
    $language = Language::factory()->createOne(['name' => 'After']);
    $activity = new Activity;
    $activity->mergeCasts(['attribute_changes' => 'collection']);
    $activity->fill([
        'description' => 'updated language',
        'event' => 'updated',
        'properties' => ['old' => ['name' => 'Wrong legacy value'], 'attributes' => ['name' => 'Wrong legacy update'], 'custom' => 'retained'],
        'attribute_changes' => ['old' => ['name' => 'Before'], 'attributes' => ['name' => 'After']],
    ]);
    $activity->subject()->associate($language);
    $activity->save();
    $activity = Activity::query()->whereKey($activity->getKey())->sole();

    $changeSet = (new DefaultActivityChangeSetBuilder)->build($activity);

    expect($changeSet->fields)->toHaveCount(1)
        ->and($changeSet->fields[0]->beforeValue)->toBe('Before')
        ->and($changeSet->fields[0]->afterValue)->toBe('After')
        ->and($changeSet->fields[0]->reversible)->toBeTrue();

    $result = RevertActivityAction::run($activity, ['name']);

    expect($result->successful)->toBeTrue()
        ->and($language->refresh()->name)->toBe('Before')
        ->and($activity->refresh()->properties?->get('custom'))->toBe('retained');
});

it('rejects a modern tracked value that no longer matches the subject', function (): void {
    Permission::findOrCreate(CapellPermission::RevertActivityLog->name());
    test()->actingAs(GlobalAuditUser::fromUser(test()->createUserWithPermission(CapellPermission::RevertActivityLog->name())));
    $language = Language::factory()->createOne(['name' => 'Subsequent edit']);
    $activity = new Activity;
    $activity->mergeCasts(['attribute_changes' => 'collection']);
    $activity->fill([
        'description' => 'updated language',
        'event' => 'updated',
        'properties' => [],
        'attribute_changes' => ['old' => ['name' => 'Before'], 'attributes' => ['name' => 'After']],
    ]);
    $activity->subject()->associate($language);
    $activity->save();

    $result = RevertActivityAction::run($activity, ['name']);

    expect($result->successful)->toBeFalse()
        ->and($result->skippedFields)->toBe(['stale_value' => ['name']])
        ->and($language->refresh()->name)->toBe('Subsequent edit');
});
